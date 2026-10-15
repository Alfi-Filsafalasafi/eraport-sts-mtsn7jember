<?php
require_once __DIR__ . '/rapor_helper.php';

function render_rapor_halaman(array $R, array $S, bool $nol = false): void {
    $k = $R['kelas'];
    $c = $R['pengaturan'];
    $nipWali = nip_tampil($k['walas_nip']);
    $nipKepala = nip_tampil($c['kepala_nip'] ?? '');
    $tempat = trim((string)($c['tempat_cetak'] ?? ''));
    $tgl = ($tempat !== '' ? $tempat . ', ' : '') . tanggal_indonesia($k['tanggal_cetak']);

    $ttdSrc = null;
    if (!empty($S['ttd']['tanda_tangan'])) {
        $v = $S['ttd']['tanda_tangan'];
        $ttdSrc = str_starts_with($v, 'data:') ? $v : 'data:image/png;base64,' . $v;
    }
    ?>
<section class="halaman">
  <div class="kop">
    <img class="logo" src="<?= e(url('assets/img/logo.png')) ?>" alt="">
    <div class="a"><?= e($c['instansi_atas'] ?? 'KEMENTERIAN AGAMA REPUBLIK INDONESIA') ?></div>
    <div class="b"><?= e($c['nama_madrasah'] ?? '') ?></div>
    <div class="c"><?= e($c['alamat'] ?? '') ?></div>
    <div class="d"><?= e(implode(' ', str_split(strtoupper((string)($c['kabupaten'] ?? ''))))) ?></div>
  </div>

  <div class="identitas">
    <table>
      <tr><td class="l">Nama</td><td>: <?= e($S['nama']) ?></td></tr>
      <tr><td class="l">NIS Lokal</td><td>: <?= e($S['nis_lokal']) ?></td></tr>
      <tr><td class="l">NIS Nasional</td><td>: <?= e($S['nis_nasional'] ?? '') ?></td></tr>
      <tr><td class="l">Madrasah</td><td>: <?= e($c['nama_singkat'] ?? '') ?></td></tr>
    </table>
    <table>
      <tr><td class="l">Kelas</td><td>: <?= e($k['nama_kelas']) ?></td></tr>
      <tr><td class="l">Fase</td><td>: <?= e($k['fase']) ?></td></tr>
      <tr><td class="l">Semester</td><td>: <?= e($k['semester']) ?></td></tr>
      <tr><td class="l">Tahun Pelajaran</td><td>: <?= e($k['tahun_ajaran']) ?></td></tr>
    </table>
  </div>

  <div class="judul">CAPAIAN HASIL BELAJAR SUMATIF TENGAH SEMESTER (STS)</div>

  <table class="nilai">
    <colgroup>
      <col style="width:6mm"><col>
      <?php for ($i = 0; $i < 13; $i++): ?><col style="width:8.2mm"><?php endfor; ?>
    </colgroup>
    <thead>
      <tr>
        <th class="mp" rowspan="2" colspan="2">Mata Pelajaran</th>
        <th colspan="7">Nilai Tugas</th>
        <th colspan="5">Nilai PH</th>
        <th rowspan="2">NILAI<br>STS</th>
      </tr>
      <tr>
        <?php for ($i = 1; $i <= 7; $i++): ?><th>TP<?= $i ?></th><?php endfor; ?>
        <?php for ($i = 1; $i <= 5; $i++): ?><th>PH<?= $i ?></th><?php endfor; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($R['baris'] as $b): ?>
      <?php if ($b['tipe'] === 'grup'): ?>
        <tr class="grup">
          <td class="no" rowspan="<?= (int)$b['span'] ?>"><?= e($b['no']) ?></td>
          <td class="mp" colspan="14"><?= e($b['nama']) ?> :</td>
        </tr>
      <?php else: $n = $S['nilai'][$b['id']] ?? null; ?>
        <tr>
          <?php if ($b['no'] !== ''): ?><td class="no"><?= e($b['no']) ?></td><?php endif; ?>
          <td class="mp"><?= $b['label'] !== '' ? e($b['label']) . ' ' : '' ?><?= e($b['nama']) ?></td>
          <?php foreach (FIELD_NILAI as $f):
              $v = $n ? tampil_nilai($n[$f]) : '';
              if ($v === '' && $nol) $v = '0';
          ?>
            <td><?= e($v) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$R['baris']): ?>
      <tr><td colspan="15" style="padding:6mm">Belum ada mata pelajaran untuk tingkat ini.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <table class="hadir">
    <colgroup><col style="width:6mm"><col><col style="width:14mm"><col style="width:12mm"></colgroup>
    <tr><th colspan="4">Ketidakhadiran :</th></tr>
    <?php foreach ([['Sakit', 'sakit'], ['Izin', 'izin'], ['Alpa', 'alpa']] as $i => [$lab, $key]): ?>
      <tr>
        <td class="no"><?= $i + 1 ?></td>
        <td><?= $lab ?></td>
        <td class="val"><?= (int)$S['hadir'][$key] ?></td>
        <td class="sat">Hari</td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="ttd">
    <div class="baris">
      <div class="blok">
        <div>&nbsp;</div>
        <div>Orang Tua / Wali</div>
        <div class="ruang"><?php if ($ttdSrc): ?><img src="<?= e($ttdSrc) ?>" alt="Tanda tangan"><?php endif; ?></div>
        <div>
          <?php if ($ttdSrc && !empty($S['ttd']['nama_penanda'])): ?>
            <strong><?= e($S['ttd']['nama_penanda']) ?></strong> (<?= e($S['ttd']['hubungan']) ?>)
          <?php else: ?>_____________________<?php endif; ?>
        </div>
      </div>
      <div class="blok kanan">
        <div><?= e($tgl) ?></div>
        <div>Wali Kelas</div>
        <div class="ruang"></div>
        <div><strong><?= e($k['walas_nama'] ?? '') ?></strong></div>
        <?php if ($nipWali): ?><div>NIP. <?= e($nipWali) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="blok kepala">
      <div>Mengetahui</div>
      <div>Kepala Madrasah</div>
      <div class="ruang"></div>
      <div><strong><?= e($c['kepala_nama'] ?? '') ?></strong></div>
      <?php if ($nipKepala): ?><div>NIP. <?= e($nipKepala) ?></div><?php endif; ?>
    </div>
  </div>
</section>
<?php
}