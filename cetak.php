<?php
require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/nilai_helper.php';
require_login();

[$periode, $daftarKelas] = kelas_periode_aktif();
$kelas = $periode ? pilih_kelas($daftarKelas, (int)($_GET['kelas'] ?? 0)) : null;

$siswa = [];
$totalMapel = 0;
$walas = null;
if ($kelas) {
    $mapel = mapel_kelas((int)$periode['id'], (int)$kelas['tingkat']);
    $totalMapel = count($mapel);
    $idMapel = implode(',', array_map('intval', array_column($mapel, 'id'))) ?: '0';

    $st = db()->prepare("SELECT a.id AS aid, s.nama, s.nis_lokal, s.is_aktif,
                                (SELECT COUNT(*) FROM nilai n WHERE n.anggota_kelas_id = a.id AND n.mapel_id IN ($idMapel)) AS terisi
                         FROM anggota_kelas a JOIN siswa s ON s.id = a.siswa_id
                         WHERE a.kelas_id = ? ORDER BY s.nama");
    $st->execute([$kelas['id']]);
    $siswa = $st->fetchAll();

    $st = db()->prepare('SELECT nama FROM users WHERE id = ?');
    $st->execute([(int)$kelas['wali_kelas_id']]);
    $walas = $st->fetchColumn() ?: null;
}

$urlKelas = $kelas ? url('cetak_rapor.php?kelas=' . (int)$kelas['id']) : '';

$title = 'Cetak Rapor';
require __DIR__ . '/app/partials/header.php';
?>
<h1 class="text-xl font-semibold mb-1">Cetak Rapor</h1>
<p class="text-sm text-gray-500 mb-4">
  <?php if ($periode): ?>Semester <?= e($periode['semester']) ?> <?= e($periode['tahun_ajaran']) ?>. Pilih kelas, lalu cetak satu siswa atau seluruh kelas.
  <?php else: ?>Belum ada periode aktif.<?php endif; ?>
</p>

<?php if (!$periode): ?>
  <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    Belum ada periode aktif. Admin perlu mengaktifkan satu periode di menu Kelola Periode.
  </div>
<?php elseif (!$daftarKelas): ?>
  <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    Belum ada kelas pada periode aktif. Admin perlu membuat kelas di menu Kelola Kelas.
  </div>
<?php else: ?>

<?= pills_kelas($daftarKelas, $kelas, 'cetak.php') ?>

<?php if ($kelas['status_rapor'] !== 'final'): ?>
<div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-circle-info mr-1"></i>Rapor kelas ini belum final, jadi cetakannya masih berupa draft.
</div>
<?php endif; ?>
<?php if (!$walas): ?>
<div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-circle-info mr-1"></i>Wali kelas belum diatur, jadi nama wali kelas di rapor kosong. Atur di menu Kelola Kelas.
</div>
<?php endif; ?>
<?php if (empty($periode['tanggal_cetak'])): ?>
<div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-circle-info mr-1"></i>Tanggal cetak rapor belum diatur di Kelola Periode, jadi rapor memakai tanggal hari ini.
</div>
<?php endif; ?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-3">
  <label class="flex items-center gap-2 text-sm">
    <input type="checkbox" id="nol" class="rounded border-gray-300 text-green-700 focus:ring-green-600">
    Tampilkan angka 0 untuk nilai yang kosong
  </label>
  <?php if ($siswa): ?>
  <a data-cetak="<?= e($urlKelas) ?>" href="<?= e($urlKelas) ?>" target="_blank"
     class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">
    <i class="fa-solid fa-print mr-1"></i>Cetak seluruh kelas (<?= count($siswa) ?> siswa)
  </a>
  <?php endif; ?>
</div>

<div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-green-50 text-green-900 text-left">
      <tr>
        <th class="px-4 py-2 font-medium w-12">No</th>
        <th class="px-4 py-2 font-medium">Nama</th>
        <th class="px-4 py-2 font-medium">NIS Lokal</th>
        <th class="px-4 py-2 font-medium">Nilai terisi</th>
        <th class="px-4 py-2 font-medium text-right">Aksi</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
    <?php foreach ($siswa as $i => $s):
        $u = $urlKelas . '&siswa=' . (int)$s['aid'];
        $t = (int)$s['terisi'];
    ?>
      <tr class="<?= $s['is_aktif'] ? '' : 'text-gray-400' ?>">
        <td class="px-4 py-2 text-gray-500"><?= $i + 1 ?></td>
        <td class="px-4 py-2"><?= e($s['nama']) ?></td>
        <td class="px-4 py-2 whitespace-nowrap"><?= e($s['nis_lokal']) ?></td>
        <td class="px-4 py-2 whitespace-nowrap">
          <?php if ($totalMapel && $t >= $totalMapel): ?>
            <span class="text-green-700"><i class="fa-solid fa-circle-check mr-1"></i>Lengkap (<?= $t ?> mapel)</span>
          <?php elseif ($t > 0): ?>
            <span class="text-amber-700"><?= $t ?> dari <?= $totalMapel ?> mapel</span>
          <?php else: ?>
            <span class="text-gray-400">Belum ada nilai</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
          <a data-cetak="<?= e($u) ?>" href="<?= e($u) ?>" target="_blank"
             class="rounded-md border border-gray-300 bg-white hover:bg-gray-50 px-3 py-1.5">
            <i class="fa-solid fa-print mr-1"></i>Cetak
          </a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$siswa): ?>
      <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Kelas ini belum berisi siswa.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
  (function () {
    const cb = document.getElementById('nol');
    const links = document.querySelectorAll('a[data-cetak]');
    function perbarui() { links.forEach(function (a) { a.href = a.dataset.cetak + (cb.checked ? '&nol=1' : ''); }); }
    cb.addEventListener('change', perbarui);
    perbarui();
  })();
</script>
<?php endif; ?>
<?php require __DIR__ . '/app/partials/footer.php'; ?>