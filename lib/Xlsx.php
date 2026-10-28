<?php
/**
 * Xlsx — kütüphane gerektirmeyen, çok sayfalı basit Excel (.xlsx) yazıcı.
 *
 * Her sayfa: ['name' => 'Özet', 'rows' => [[...], ...], 'widths' => [24, 12, ...]].
 * İlk satır başlık sayılır (kalın, sabitlenmiş). Sayılar sayı, diğerleri metin olarak yazılır.
 * ZipArchive gerekir (toplu ZIP indirme de aynı eklentiyi kullanır).
 */
class Xlsx {

    public static function available(): bool {
        return class_exists('ZipArchive');
    }

    /** @param array<int,array{name:string,rows:array,widths?:array}> $sheets  @return string .xlsx dosyasının içeriği */
    public static function build(array $sheets): string {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Excel dosyası oluşturulamadı.');

        $names = [];
        foreach ($sheets as $i => $s) $names[$i] = self::sheetName((string)$s['name'], $names);

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($sheets as $i => $_) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheets as $i => $_) {
            $n = $i + 1;
            $wb   .= '<sheet name="' . self::x($names[$i]) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $n = count($sheets) + 1;
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels . '</Relationships>');

        // 0: normal, 1: kalın başlık (açık mavi zemin)
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF1C2561"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEFB"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');

        foreach ($sheets as $i => $s) $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', self::sheetXml($s));
        $zip->close();
        $data = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private static function sheetXml(array $s): string {
        $rows = array_values($s['rows'] ?? []);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($rows) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if (!empty($s['widths'])) {
            $xml .= '<cols>';
            foreach (array_values($s['widths']) as $c => $w) $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($rows as $r => $row) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach (array_values((array)$row) as $c => $v) {
                if ($v === null || $v === '') continue;
                $ref = self::col($c) . ($r + 1);
                $st = $r === 0 ? ' s="1"' : '';
                if ((is_int($v) || is_float($v)) && is_finite((float)$v)) {
                    $xml .= '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '"' . $st . ' t="inlineStr"><is><t xml:space="preserve">' . self::x((string)$v) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    }

    /** 0 → A, 25 → Z, 26 → AA */
    private static function col(int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    /** Excel sayfa adı: en fazla 31 karakter, : \ / ? * [ ] yok, benzersiz */
    private static function sheetName(string $n, array $used): string {
        $n = trim(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $n)) ?: 'Sayfa';
        $n = mb_substr($n, 0, 31);
        $base = $n; $k = 2;
        while (in_array($n, $used, true)) $n = mb_substr($base, 0, 28) . ' ' . $k++;
        return $n;
    }

    private static function x(string $s): string {
        // XML 1.0'da izin verilmeyen kontrol karakterleri atılır
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
