<?php
/**
 * Mengirim file template .xlsx berisi satu baris judul.
 * $kolom = [['Judul kolom', lebar], ...] (maksimal 26 kolom). Semua kolom berformat Teks.
 */
function kirim_template_xlsx(string $namaFile, array $kolom): never {
    $m  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $r  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $pr = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $h  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    $cols = '';
    $sel = '';
    foreach ($kolom as $i => [$judul, $lebar]) {
        $n = $i + 1;
        $huruf = chr(65 + $i);
        $cols .= "<col min=\"$n\" max=\"$n\" width=\"$lebar\" style=\"1\" customWidth=\"1\"/>";
        $sel  .= "<c r=\"{$huruf}1\" s=\"2\" t=\"inlineStr\"><is><t>" . htmlspecialchars($judul, ENT_XML1) . '</t></is></c>';
    }

    $berkas = [
        '[Content_Types].xml' => $h . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => $h . "<Relationships xmlns=\"$pr\">"
            . "<Relationship Id=\"rId1\" Type=\"$r/officeDocument\" Target=\"xl/workbook.xml\"/></Relationships>",
        'xl/workbook.xml' => $h . "<workbook xmlns=\"$m\" xmlns:r=\"$r\"><sheets>"
            . '<sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $h . "<Relationships xmlns=\"$pr\">"
            . "<Relationship Id=\"rId1\" Type=\"$r/worksheet\" Target=\"worksheets/sheet1.xml\"/>"
            . "<Relationship Id=\"rId2\" Type=\"$r/styles\" Target=\"styles.xml\"/></Relationships>",
        'xl/styles.xml' => $h . "<styleSheet xmlns=\"$m\">"
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => $h . "<worksheet xmlns=\"$m\"><cols>$cols</cols>"
            . "<sheetData><row r=\"1\">$sel</row></sheetData></worksheet>",
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'tpl');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($berkas as $nama => $isi) $zip->addFromString($nama, $isi);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}
