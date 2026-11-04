<?php
/**
 * Penulis .xlsx minimal (tanpa dependensi, hanya ext-zip).
 * Dipakai untuk laporan admin: sheet bergaya, lebar kolom, header beku, autofilter.
 *
 * Sel: null | scalar | [value, styleIndex]. int/float → angka, string → teks.
 * Style index merujuk ke XlsxWriter::STYLE_* di bawah.
 */
class XlsxWriter {
    const S_DEFAULT = 0;
    const S_TITLE = 1;        // judul besar
    const S_MUTED = 2;        // teks abu-abu kecil
    const S_TH_LEFT = 3;      // header tabel
    const S_TH_CENTER = 4;
    const S_TH_RIGHT = 5;
    const S_TEXT = 6;         // sel tabel
    const S_INT = 7;
    const S_RUPIAH = 8;
    const S_DATETIME = 9;
    const S_CODE = 10;        // kode tiket (monospace)
    const S_LABEL = 11;       // label ringkasan
    const S_VAL_TEXT = 12;
    const S_VAL_INT = 13;
    const S_VAL_RUPIAH = 14;
    const S_SECTION = 15;     // judul seksi
    const S_TOT_LABEL = 16;   // baris total
    const S_TOT_INT = 17;
    const S_TOT_RUPIAH = 18;
    const S_SUBTITLE = 19;
    const S_TOT_BLANK = 20;

    private array $sheets = [];

    /**
     * @param array $opts widths[], rowHeights[row=>pt], freeze (jumlah baris beku), filter ('A4:L20'), landscape
     */
    public function addSheet(string $name, array $rows, array $opts = []): void {
        $this->sheets[] = ['name' => mb_substr($name, 0, 31), 'rows' => $rows, 'opts' => $opts];
    }

    /** Serial tanggal Excel dari timestamp (waktu lokal server). */
    public static function serial(int $ts): float {
        return ($ts + (int) date('Z', $ts)) / 86400 + 25569;
    }

    public function output(string $filename): bool {
        if (!class_exists('ZipArchive')) return false;

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return false;

        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $names = '';
        foreach ($this->sheets as $i => $s) {
            $id = $i + 1;
            $wb   .= '<sheet name="' . self::esc($s['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
            if (!empty($s['opts']['filter'])) {
                $abs = preg_replace('/([A-Z]+)(\d+)/', '$$1$$$2', $s['opts']['filter']);
                $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . self::esc($s['name']) . '\'!' . $abs . '</definedName>';
            }
            $zip->addFromString('xl/worksheets/sheet' . $id . '.xml', $this->sheetXml($s['rows'], $s['opts'], $i === 0));
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $wb   .= '</sheets>' . ($names ? '<definedNames>' . $names . '</definedNames>' : '') . '</workbook>';

        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->close();

        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($tmp);
        unlink($tmp);
        return true;
    }

    private function sheetXml(array $rows, array $o, bool $selected): string {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
           . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
           . '<sheetViews><sheetView showGridLines="0" workbookViewId="0"' . ($selected ? ' tabSelected="1"' : '') . '>';
        if (!empty($o['freeze'])) {
            $f = (int) $o['freeze'];
            $x .= '<pane ySplit="' . $f . '" topLeftCell="A' . ($f + 1) . '" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/>';
        }
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="20" customHeight="1"/>';

        if (!empty($o['widths'])) {
            $x .= '<cols>';
            foreach ($o['widths'] as $i => $w) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }

        $x .= '<sheetData>';
        foreach ($rows as $r => $cells) {
            $rn = $r + 1;
            $ht = isset($o['rowHeights'][$rn]) ? ' ht="' . $o['rowHeights'][$rn] . '" customHeight="1"' : '';
            $x .= '<row r="' . $rn . '"' . $ht . '>';
            foreach ($cells as $c => $cell) {
                if ($cell === null) continue;
                [$v, $s] = is_array($cell) ? [$cell[0], $cell[1] ?? 0] : [$cell, 0];
                $ref = chr(65 + $c) . $rn;
                $sa  = $s ? ' s="' . $s . '"' : '';
                if ($v === null || $v === '') {
                    $x .= '<c r="' . $ref . '"' . $sa . '/>';
                } elseif (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '"' . $sa . '><v>' . $v . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '"' . $sa . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if (!empty($o['filter'])) $x .= '<autoFilter ref="' . $o['filter'] . '"/>';
        $x .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="' . (!empty($o['landscape']) ? 'landscape' : 'portrait') . '" fitToWidth="1" fitToHeight="0"/>'
            . '</worksheet>';
        return $x;
    }

    private function stylesXml(): string {
        $hair = '<color rgb="FFE5E5E5"/>';
        $blk  = '<color rgb="FF000000"/>';
        $fonts = [
            '<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>',                       // 0
            '<font><b/><sz val="20"/><color rgb="FF000000"/><name val="Calibri"/></font>',                   // 1 title
            '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>',                   // 2 header
            '<font><sz val="10"/><color rgb="FF737373"/><name val="Calibri"/></font>',                       // 3 muted
            '<font><b/><sz val="13"/><color rgb="FF000000"/><name val="Calibri"/></font>',                   // 4 subtitle
            '<font><b/><sz val="10"/><color rgb="FF000000"/><name val="Consolas"/></font>',                  // 5 code
            '<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font>',                   // 6 bold
        ];
        $fills = [
            '<fill><patternFill patternType="none"/></fill>',
            '<fill><patternFill patternType="gray125"/></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FF000000"/><bgColor indexed="64"/></patternFill></fill>',
        ];
        $borders = [
            '<border><left/><right/><top/><bottom/><diagonal/></border>',
            '<border><left/><right/><top/><bottom style="thin">' . $hair . '</bottom><diagonal/></border>',
            '<border><left/><right/><top/><bottom style="medium">' . $blk . '</bottom><diagonal/></border>',
            '<border><left/><right/><top style="medium">' . $blk . '</top><bottom/><diagonal/></border>',
        ];
        $numFmts = [164 => '"Rp "#,##0', 165 => 'd mmm yyyy, hh:mm', 166 => '#,##0'];

        // [numFmt, font, fill, border, horizontal, wrap]
        $xfs = [
            [0, 0, 0, 0, null],           // 0
            [0, 1, 0, 0, 'left'],         // 1 title
            [0, 3, 0, 0, 'left'],         // 2 muted
            [0, 2, 2, 0, 'left'],         // 3 th left
            [0, 2, 2, 0, 'center'],       // 4 th center
            [0, 2, 2, 0, 'right'],        // 5 th right
            [0, 0, 0, 1, 'left'],         // 6 text
            [166, 0, 0, 1, 'center'],     // 7 int
            [164, 0, 0, 1, 'right'],      // 8 rupiah
            [165, 0, 0, 1, 'left'],       // 9 datetime
            [0, 5, 0, 1, 'left'],         // 10 code
            [0, 3, 0, 1, 'left'],         // 11 label
            [0, 6, 0, 1, 'left'],         // 12 val text
            [166, 6, 0, 1, 'left'],       // 13 val int
            [164, 6, 0, 1, 'left'],       // 14 val rupiah
            [0, 6, 0, 2, 'left'],         // 15 section
            [0, 6, 0, 3, 'right'],        // 16 total label
            [166, 6, 0, 3, 'center'],     // 17 total int
            [164, 6, 0, 3, 'right'],      // 18 total rupiah
            [0, 4, 0, 0, 'left'],         // 19 subtitle
            [0, 0, 0, 3, 'left'],         // 20 total blank
        ];

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $x .= '<numFmts count="' . count($numFmts) . '">';
        foreach ($numFmts as $id => $code) $x .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::esc($code) . '"/>';
        $x .= '</numFmts>';
        $x .= '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>';
        $x .= '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>';
        $x .= '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>';
        $x .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $x .= '<cellXfs count="' . count($xfs) . '">';
        foreach ($xfs as [$nf, $fo, $fi, $bo, $h]) {
            $x .= '<xf numFmtId="' . $nf . '" fontId="' . $fo . '" fillId="' . $fi . '" borderId="' . $bo . '" xfId="0"'
                . ($nf ? ' applyNumberFormat="1"' : '') . ' applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
                . '<alignment' . ($h ? ' horizontal="' . $h . '"' : '') . ' vertical="center"/></xf>';
        }
        $x .= '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        return $x;
    }

    private static function esc(string $s): string {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
