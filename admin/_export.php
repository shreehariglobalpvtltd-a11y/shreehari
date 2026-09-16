<?php
/**
 * admin/_export.php — tiny shared exporters for the admin list pages
 * (Customers, Agents). CSV opens in Excel / Google Sheets everywhere; the
 * .xlsx writer is a hand-built minimal Office Open XML package (a copy of
 * the one bookings export.php uses) so no library is needed.
 */
declare(strict_types=1);

/** Stream a UTF-8 CSV (BOM so Excel reads Nepali / Hindi names). */
function shg_export_csv(string $filename, array $header, array $data): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($data as $row) {
        // Formula-injection guard: a cell starting with = + - @ is prefixed.
        fputcsv($out, array_map(static function ($v): string {
            $s = (string) $v;
            return ($s !== '' && strpbrk($s[0], '=+-@') !== false) ? "'" . $s : $s;
        }, array_values($row)));
    }
    fclose($out);
    exit;
}

/** Stream a minimal valid .xlsx; $numericCols are 0-indexed numeric columns. */
function shg_export_xlsx(string $filename, string $sheetName, array $header, array $data, array $numericCols = []): void
{
    if (!class_exists('ZipArchive')) {
        shg_export_csv(preg_replace('/\.xlsx$/', '.csv', $filename) ?: $filename, $header, $data);
    }
    $numeric = array_flip($numericCols);
    $xesc    = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $colRef  = static function (int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) { $s = chr(65 + (($i - 1) % 26)) . $s; }
        return $s;
    };
    $rowXml = static function (array $cells, int $rowNum, bool $headerRow) use ($numeric, $xesc, $colRef): string {
        $x = '<row r="' . $rowNum . '">';
        foreach (array_values($cells) as $ci => $val) {
            $ref = $colRef($ci) . $rowNum;
            if (!$headerRow && isset($numeric[$ci]) && is_numeric($val)) {
                $x .= '<c r="' . $ref . '"><v>' . $xesc($val) . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $xesc($val) . '</t></is></c>';
            }
        }
        return $x . '</row>';
    };
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . $rowXml($header, 1, true);
    $r = 2;
    foreach ($data as $row) { $sheet .= $rowXml($row, $r++, false); }
    $sheet .= '</sheetData></worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . htmlspecialchars($sheetName, ENT_QUOTES | ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}
