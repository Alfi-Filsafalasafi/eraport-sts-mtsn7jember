<?php
require __DIR__ . '/app/bootstrap.php';
require_login();

$p = periode_aktif();
$jmlGuru  = (int)db()->query("SELECT COUNT(*) FROM users WHERE is_aktif = 1 AND role = 'guru'")->fetchColumn();
$jmlSiswa = (int)db()->query('SELECT COUNT(*) FROM siswa WHERE is_aktif = 1')->fetchColumn();

$kelas = [];
if ($p) {
    $st = db()->prepare(
        'SELECT k.id, k.nama_kelas, k.status_rapor, u.nama AS walas,
                (SELECT COUNT(*) FROM anggota_kelas ak WHERE ak.kelas_id = k.id) AS jml
         FROM kelas k LEFT JOIN users u ON u.id = k.wali_kelas_id
         WHERE k.periode_id = ? ORDER BY k.tingkat, k.nama_kelas');
    $st->execute([$p['id']]);
    $kelas = $st->fetchAll();
}

$title = 'Beranda';
require __DIR__ . '/app/partials/header.php';
?>
<h1 class="text-xl font-semibold">Halo, <?= e(user()['nama']) ?></h1>
<p class="text-sm text-gray-500 mb-6">
  <?php if ($p): ?>
    Periode aktif: Semester <?= e($p['semester']) ?> tahun pelajaran <?= e($p['tahun_ajaran']) ?>.
  <?php else: ?>
    Belum ada periode aktif. Admin perlu mengaktifkan satu periode.
  <?php endif; ?>
</p>

<dl class="grid grid-cols-3 gap-4 mb-8 max-w-xl text-sm">
  <div><dt class="text-gray-500">Kelas</dt><dd class="text-2xl font-semibold"><?= count($kelas) ?></dd></div>
  <div><dt class="text-gray-500">Siswa aktif</dt><dd class="text-2xl font-semibold"><?= $jmlSiswa ?></dd></div>
  <div><dt class="text-gray-500">Guru</dt><dd class="text-2xl font-semibold"><?= $jmlGuru ?></dd></div>
</dl>

<h2 class="font-semibold mb-2">Daftar kelas</h2>
<div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-green-50 text-green-900 text-left">
      <tr>
        <th class="px-4 py-2 font-medium">Kelas</th>
        <th class="px-4 py-2 font-medium">Wali kelas</th>
        <th class="px-4 py-2 font-medium">Siswa</th>
        <th class="px-4 py-2 font-medium">Status rapor</th>
        <th class="px-4 py-2 font-medium text-right">Aksi</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
    <?php foreach ($kelas as $k): ?>
      <tr>
        <td class="px-4 py-2 font-medium"><?= e($k['nama_kelas']) ?></td>
        <td class="px-4 py-2"><?= e($k['walas'] ?? '-') ?></td>
        <td class="px-4 py-2"><?= (int)$k['jml'] ?></td>
        <td class="px-4 py-2">
          <?php if ($k['status_rapor'] === 'final'): ?>
            <span class="text-green-700"><i class="fa-solid fa-circle-check mr-1"></i>Final</span>
          <?php else: ?>
            <span class="text-gray-500"><i class="fa-regular fa-pen-to-square mr-1"></i>Draft</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
          <a href="<?= url('nilai.php?kelas=' . (int)$k['id']) ?>" class="text-green-700 hover:underline mr-3">Input nilai</a>
          <a href="<?= url('ketidakhadiran.php?kelas=' . (int)$k['id']) ?>" class="text-green-700 hover:underline">Ketidakhadiran</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$kelas): ?>
      <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Belum ada kelas pada periode ini.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/app/partials/footer.php'; ?>