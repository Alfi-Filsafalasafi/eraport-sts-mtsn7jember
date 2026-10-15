<?php
require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/rapor_view.php';
require_login();

$kid = (int)($_GET['kelas'] ?? 0);
$aid = (int)($_GET['siswa'] ?? 0);
$nol = isset($_GET['nol']);

$R = muat_rapor_kelas($kid, $aid ?: null);
if (!$R || !$R['siswa']) {
    flash('Data rapor tidak ditemukan atau kelas belum berisi siswa.');
    redirect('cetak.php' . ($kid ? '?kelas=' . $kid : ''));
}

$k = $R['kelas'];
$jml = count($R['siswa']);
$judul = $jml === 1
    ? 'Rapor STS - ' . $R['siswa'][0]['nama'] . ' (' . $k['nama_kelas'] . ')'
    : 'Rapor STS - Kelas ' . $k['nama_kelas'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($judul) ?></title>
  <link rel="icon" type="image/png" href="<?= e(url('assets/img/logo.png')) ?>">
  <style>
    :root { --ruang-ttd: 15mm; }
    * { box-sizing: border-box; }
    body {
      margin: 0; background: #e5e7eb; color: #000;
      font-family: Calibri, Carlito, 'Segoe UI', Arial, sans-serif; font-size: 10.5pt;
      -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }

    /* Bilah atas (tidak ikut tercetak) */
    .bar { position: fixed; top: 0; left: 0; right: 0; z-index: 10; background: #166534; color: #fff;
           padding: 8px 16px; display: flex; gap: 14px; align-items: center;
           font: 500 14px/1.2 Inter, system-ui, sans-serif; }
    .bar a { color: #fff; text-decoration: none; }
    .bar .sp { flex: 1; }
    .bar .draft { background: #fef3c7; color: #92400e; border-radius: 4px; padding: 2px 8px; font-size: 12px; }
    .bar button { background: #fff; color: #166534; border: 0; border-radius: 6px; padding: 7px 14px;
                  font: 600 14px Inter, system-ui, sans-serif; cursor: pointer; }
    .wrap { padding-top: 54px; }

    /* Halaman A4 */
    .halaman { width: 210mm; min-height: 296mm; margin: 12px auto; padding: 8mm 12mm; background: #fff;
               box-shadow: 0 1px 4px rgba(0,0,0,.25); }

    .kop { position: relative; text-align: center; border-bottom: 2px solid #000; padding-bottom: 2mm; min-height: 21mm; }
    .kop .logo { position: absolute; left: 2mm; top: 0; height: 19mm; width: auto; }
    .kop .a { font-size: 12pt; font-weight: 700; }
    .kop .b { font-size: 15pt; font-weight: 700; line-height: 1.15; }
    .kop .c { font-size: 8.5pt; font-weight: 700; }
    .kop .d { font-size: 8.5pt; font-weight: 700; letter-spacing: .2em; }

    table { border-collapse: collapse; width: 100%; }
    .identitas { display: grid; grid-template-columns: 1.4fr 1fr; gap: 8mm; margin: 4mm 0 4mm; font-size: 10.5pt; }
    .identitas td { padding: .3mm 0; vertical-align: top; }
    .identitas td.l { width: 30mm; }
    .identitas table:nth-child(2) td.l { width: 34mm; }
    .judul { text-align: center; font-weight: 700; font-size: 10.5pt; margin: 4mm 0 2.5mm; }

    .nilai { table-layout: fixed; font-size: 9.5pt; }
    .nilai th, .nilai td { border: 1px solid #000; padding: 1px 2px; text-align: center; line-height: 1.25; }
    .nilai th { background: #a9d18e; font-weight: 700; }
    .nilai th.mp { text-align: center; }
    .nilai td.mp { text-align: left; padding-left: 3px; }
    .nilai tr.grup td.mp { background: #d9d9d9; font-weight: 700; }

    .hadir { margin-top: 4mm; font-size: 10pt; }
    .hadir th, .hadir td { border: 1px solid #000; padding: 1px 3px; line-height: 1.25; }
    .hadir th { background: #d9d9d9; text-align: left; }
    .hadir td.no { text-align: center; }
    .hadir td.val { text-align: right; border-right: 0; }
    .hadir td.sat { border-left: 0; }

    .ttd { margin-top: 4mm; font-size: 10.5pt; break-inside: avoid; }
    .ttd .baris { display: grid; grid-template-columns: 1fr 1fr; }
    .ttd .blok { line-height: 1.3; }
    .ttd .kanan { padding-left: 12mm; }
    .ttd .kepala { width: 66mm; margin: 2mm auto 0; }
    .ttd .ruang { height: var(--ruang-ttd); display: flex; align-items: center; }
    .ttd .ruang img { max-height: var(--ruang-ttd); max-width: 55mm; }

    @page { size: A4 portrait; margin: 0; }
    @media print {
      body { background: #fff; }
      .bar { display: none !important; }
      .wrap { padding-top: 0; }
      .halaman { margin: 0; box-shadow: none; min-height: 0; break-after: page; }
      .halaman:last-child { break-after: auto; }
    }
  </style>
</head>
<body>
  <div class="bar">
    <a href="<?= e(url('cetak.php?kelas=' . $kid)) ?>">&larr; Kembali</a>
    <span><?= $jml === 1 ? e($R['siswa'][0]['nama']) : $jml . ' rapor, kelas ' . e($k['nama_kelas']) ?></span>
    <?php if ($k['status_rapor'] !== 'final'): ?><span class="draft">Draft, belum final</span><?php endif; ?>
    <span class="sp"></span>
    <button type="button" onclick="window.print()">Cetak</button>
  </div>
  <div class="wrap">
    <?php foreach ($R['siswa'] as $S) render_rapor_halaman($R, $S, $nol); ?>
  </div>
</body>
</html>