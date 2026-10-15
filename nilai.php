<?php
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/nilai_helper.php';
require_login();

[$periode, $daftarKelas] = kelas_periode_aktif();
$kelas = $periode ? pilih_kelas($daftarKelas, (int)($_GET['kelas'] ?? 0)) : null;

$mapel = [];
$terisi = [];
if ($kelas) {
    $mapel = mapel_kelas((int)$periode['id'], (int)$kelas['tingkat']);
    $st = db()->prepare('SELECT n.mapel_id, COUNT(*)
                         FROM nilai n JOIN anggota_kelas a ON a.id = n.anggota_kelas_id
                         WHERE a.kelas_id = ? GROUP BY n.mapel_id');
    $st->execute([$kelas['id']]);
    $terisi = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Susun baris: judul grup (PAI, Muatan Lokal) dan mapel, lengkap dengan nomornya
$rows = [];
$no = 0;
$lastParent = 0;
$huruf = 0;
foreach ($mapel as $m) {
    $induk = (int)$m['parent_id'];
    if ($induk) {
        if ($induk !== $lastParent) { $lastParent = $induk; $huruf = 0; $rows[] = ['grup', (string)++$no, $m['induk']]; }
        $rows[] = ['mapel', chr(65 + min($huruf++, 25)) . '.', $m];
    } else {
        $lastParent = 0;
        $rows[] = ['mapel', (string)++$no, $m];
    }
}

$title = 'Input Nilai';
require __DIR__ . '/app/partials/header.php';
?>
<h1 class="text-xl font-semibold mb-1">Input Nilai</h1>
<p class="text-sm text-gray-500 mb-4">
  <?php if ($periode): ?>Semester <?= e($periode['semester']) ?> <?= e($periode['tahun_ajaran']) ?>. Pilih kelas, lalu pilih mapel yang akan diisi.
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

<?= pills_kelas($daftarKelas, $kelas, 'nilai.php') ?>

<?php if ($kelas['status_rapor'] === 'final'): ?>
<div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-lock mr-1"></i>Rapor kelas <?= e($kelas['nama_kelas']) ?> sudah final, jadi nilainya terkunci dan hanya bisa dilihat.
</div>
<?php endif; ?>

<div class="flex flex-wrap items-center justify-between gap-2 mb-2">
  <h2 class="font-semibold">Mapel kelas <?= e($kelas['nama_kelas']) ?> <span class="font-normal text-gray-500">(<?= (int)$kelas['jml'] ?> siswa)</span></h2>
  <a href="<?= url('ketidakhadiran.php?kelas=' . (int)$kelas['id']) ?>" class="text-sm text-green-700 hover:underline">
    <i class="fa-solid fa-user-clock mr-1"></i>Isi ketidakhadiran kelas ini
  </a>
</div>

<?php if ((int)$kelas['jml'] === 0): ?>
  <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Kelas ini belum berisi siswa.</div>
<?php elseif (!$rows): ?>
  <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    Belum ada mapel untuk tingkat <?= (int)$kelas['tingkat'] ?> pada periode ini. Atur di menu Kelola Mapel.
  </div>
<?php else: ?>
<div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-green-50 text-green-900 text-left">
      <tr>
        <th class="px-4 py-2 font-medium w-14">No</th>
        <th class="px-4 py-2 font-medium">Mata pelajaran</th>
        <th class="px-4 py-2 font-medium">Nilai terisi</th>
        <th class="px-4 py-2 font-medium text-right">Aksi</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
    <?php foreach ($rows as [$tipe, $nomor, $m]): ?>
      <?php if ($tipe === 'grup'): ?>
        <tr class="bg-gray-50 font-medium">
          <td class="px-4 py-2"><?= e($nomor) ?></td>
          <td class="px-4 py-2" colspan="3"><?= e($m) ?></td>
        </tr>
      <?php else:
        $n = (int)($terisi[$m['id']] ?? 0);
        $jml = (int)$kelas['jml'];
        $sub = (bool)$m['parent_id'];
      ?>
        <tr>
          <td class="px-4 py-2 <?= $sub ? 'text-right text-gray-500' : '' ?>"><?= e($nomor) ?></td>
          <td class="px-4 py-2 <?= $sub ? 'pl-8' : '' ?>"><?= e($m['nama']) ?></td>
          <td class="px-4 py-2 whitespace-nowrap">
            <?php if ($n >= $jml): ?>
              <span class="text-green-700"><i class="fa-solid fa-circle-check mr-1"></i><?= $n ?> dari <?= $jml ?> siswa</span>
            <?php elseif ($n > 0): ?>
              <span class="text-amber-700"><?= $n ?> dari <?= $jml ?> siswa</span>
            <?php else: ?>
              <span class="text-gray-400">Belum diisi</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="<?= url('nilai_input.php?kelas=' . (int)$kelas['id'] . '&mapel=' . (int)$m['id']) ?>"
               class="rounded-md border border-gray-300 bg-white hover:bg-gray-50 px-3 py-1.5">
              <?= $kelas['status_rapor'] === 'final' ? 'Lihat' : 'Isi nilai' ?>
            </a>
          </td>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/app/partials/footer.php'; ?>