<?php
/** Mengembalikan [nomorBaris => [sel1, sel2, ...]] */
function baca_tabel(string $path, string $ext): array {
    return $ext === 'xlsx' ? baca_xlsx($path) : baca_csv($path);
}

function baca_csv(string $path): array {
    $raw = (string)file_get_contents($path);
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);

    $baris = [];
    $no = 0;
    $delim = null;
    while (($line = fgets($fh)) !== false) {
        $no++;
        if (stripos($line, 'sep=') === 0) continue;
        if ($delim === null) $delim = substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
        $baris[$no] = array_map('trim', str_getcsv(rtrim($line, "\r\n"), $delim, '"', ''));
    }
    fclose($fh);
    return $baris;
}

/** Memuat XML sebagai DOM yang mengenali namespace. */
function xml_dom(string $xml): DOMDocument {
    $dom = new DOMDocument();
    $lama = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($lama);
    if (!$ok) throw new RuntimeException('Isi file Excel tidak terbaca.');
    return $dom;
}

/** Menggabungkan teks semua elemen <t> di dalam $el (kecuali teks fonetik <rPh>). */
function xml_teks(DOMElement $el): string {
    $t = '';
    foreach ($el->getElementsByTagNameNS('*', 't') as $n) {
        if ($n->parentNode && $n->parentNode->localName === 'rPh') continue;
        $t .= $n->textContent;
    }
    return $t;
}

function baca_xlsx(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('File Excel tidak bisa dibuka.');

    // Tabel teks bersama (sharedStrings)
    $strings = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml !== false) {
        foreach (xml_dom($xml)->getElementsByTagNameNS('*', 'si') as $si) {
            $strings[] = xml_teks($si);
        }
    }

    // Sheet pertama
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('~^xl/worksheets/[^/]+\.xml$~', $zip->getNameIndex($i))) {
                $sheetXml = $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();
    if ($sheetXml === false || $sheetXml === null) throw new RuntimeException('Isi file Excel tidak terbaca.');

    $baris = [];
    $auto = 0;
    foreach (xml_dom($sheetXml)->getElementsByTagNameNS('*', 'row') as $row) {
        $auto++;
        $no = (int)$row->getAttribute('r') ?: $auto;
        $sel = [];
        $kolAuto = -1;

        foreach ($row->childNodes as $c) {
            if (!($c instanceof DOMElement) || $c->localName !== 'c') continue;
            $kolAuto++;

            $kol = $kolAuto;
            if (preg_match('/^([A-Z]+)\d*$/', $c->getAttribute('r'), $m)) {
                $kol = 0;
                foreach (str_split($m[1]) as $ch) $kol = $kol * 26 + (ord($ch) - 64);
                $kol--;
                $kolAuto = $kol;
            }

            $tipe = $c->getAttribute('t');
            $v = $c->getElementsByTagNameNS('*', 'v')->item(0);
            if ($tipe === 's') {
                $val = $strings[(int)($v?->textContent)] ?? '';
            } elseif ($tipe === 'inlineStr') {
                $val = xml_teks($c);
            } else {
                $val = $v ? $v->textContent : '';
            }
            $sel[$kol] = trim($val);
        }

        $out = [];
        for ($i = 0, $max = $sel ? max(array_keys($sel)) : -1; $i <= $max; $i++) $out[] = $sel[$i] ?? '';
        $baris[$no] = $out;
    }
    return $baris;
}
