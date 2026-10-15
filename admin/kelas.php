<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/kelas_helper.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['aksi'] ?? '') === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT nama_kelas, status_rapor,
                                    (SELECT COUNT(*) FROM anggota_kelas a WHERE a.kelas_id = kelas.id) AS jml
                             FROM kelas WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            flash('Kelas tidak ditemukan.');
        } elseif ($r['status_rapor'] === 'final') {
            flash('Kelas yang sudah final tidak bisa dihapus.');
        } elseif ((int)$r['jml'] > 0) {
            flash("Kelas {$r['nama_kelas']} masih berisi {$r['jml']} siswa. Keluarkan atau pindahkan siswanya dulu.");
        } else {
            db()->prepare('DELETE FROM kelas WHERE id = ?')->execute([$id]);
            flash("Kelas {$r['nama_kelas']} dihapus.", 'success');
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$periodeList = daftar_periode();
$pid = (int)($_GET['periode'] ?? 0);
if (!in_array($pid, array_map('intval', array_column($periodeList, 'id')), true)) {
    $aktif = periode_aktif();
    $pid = $aktif ? (int)$aktif['id'] : (int)($periodeList[0]['id'] ?? 0);
}
$periodeDipilih = null;
foreach ($periodeList as $p) if ((int)$p['id'] === $pid) $periodeDipilih = $p;

$daftar = [];
if ($pid) {
    $st = db()->prepare('SELECT k.id, k.nama_kelas, k.fase, k.status_rapor, u.nama AS walas,
                                (SELECT COUNT(*) FROM anggota_kelas a WHERE a.kelas_id = k.id) AS jml
                         FROM kelas k LEFT JOIN users u ON u.id = k.wali_kelas_id
                         WHERE k.periode_id = ? ORDER BY k.tingkat, k.nama_kelas');
    $st->execute([$pid]);
    $daftar = $st->fetchAll();
}
$totalSiswa = array_sum(array_column($daftar, 'jml'));
$tanpaWali = count(array_filter($daftar, fn($k) => !$k['walas']));

$title = 'Kelola Kelas';
require __DIR__ . '/../app/partials/header.php';
?>
<div class="flex flex-wrap items-end justify-between gap-3 mb-4">
  <div>
    <h1 class="text-xl font-semibold">Kelola Kelas</h1>
    <p class="text-sm text-gray-500"><?= count($daftar) ?> kelas, <?= (int)$totalSiswa ?> siswa.</p>
  </div>
  <?php if ($pid): ?>
  <div class="flex gap-2">
    <a href="<?= url('admin/kelas_salin.php?tujuan=' . $pid) ?>" class="rounded-md border border-gray-300 bg-white hover:bg-gray-50 text-sm font-medium py-2 px-4">
      <i class="fa-regular fa-copy mr-1 text-green-700"></i>Salin dari periode lain
    </a>
    <a href="<?= url('admin/kelas_form.php?periode=' . $pid) ?>" class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">
      <i class="fa-solid fa-plus mr-1"></i>Tambah kelas
    </a>
  </div>
  <?php endif; ?>
</div>

<?php if (!$periodeList): ?>
  <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    Belum ada periode. Buat periode dulu di menu Kelola Periode.
  </div>
<?php else: ?>

<form method="get" class="mb-4">
  <label for="periode" class="block text-sm font-medium mb-1">Periode</label>
  <select id="periode" name="periode" onchange="this.form.submit()"
          class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none">
    <?php foreach ($periodeList as $p): ?>
      <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $pid ? 'selected' : '' ?>><?= e(label_periode($p)) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<?php if ($periodeDipilih && !$periodeDipilih['is_aktif']): ?>
<div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-circle-info mr-1"></i>Ini bukan periode aktif. Perubahan di sini tidak memengaruhi data periode aktif.
</div>
<?php endif; ?>
<?php if ($tanpaWali > 0): ?>
<div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-circle-info mr-1"></i><?= $tanpaWali ?> kelas belum punya wali kelas.
</div>
<?php endif; ?>

<div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-green-50 text-green-900 text-left">
      <tr>
        <th class="px-4 py-2 font-medium">Kelas</th>
        <th class="px-4 py-2 font-medium">Fase</th>
        <th class="px-4 py-2 font-medium">Wali kelas</th>
        <th class="px-4 py-2 font-medium">Siswa</th>
        <th class="px-4 py-2 font-medium">Status rapor</th>
        <th class="px-4 py-2 font-medium text-right">Aksi</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
    <?php foreach ($daftar as $k): ?>
      <tr>
        <td class="px-4 py-2 font-medium"><?= e($k['nama_kelas']) ?></td>
        <td class="px-4 py-2"><?= e($k['fase']) ?></td>
        <td class="px-4 py-2"><?= $k['walas'] ? e($k['walas']) : '<span class="text-gray-400">Belum diatur</span>' ?></td>
        <td class="px-4 py-2"><?= (int)$k['jml'] ?></td>
        <td class="px-4 py-2">
          <?php if ($k['status_rapor'] === 'final'): ?>
            <span class="text-green-700"><i class="fa-solid fa-circle-check mr-1"></i>Final</span>
          <?php else: ?>
            <span class="text-gray-500"><i class="fa-regular fa-pen-to-square mr-1"></i>Draft</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
          <a href="<?= url('admin/kelas_anggota.php?id=' . (int)$k['id']) ?>" class="text-gray-500 hover:text-green-700 mr-3" title="Atur siswa">
            <i class="fa-solid fa-people-group"></i>
          </a>
          <?php if ($k['status_rapor'] !== 'final'): ?>
          <a href="<?= url('admin/kelas_form.php?id=' . (int)$k['id']) ?>" class="text-gray-500 hover:text-green-700 mr-3" title="Edit">
            <i class="fa-solid fa-pen"></i>
          </a>
          <form method="post" class="inline" onsubmit="return confirm('Hapus kelas <?= e(addslashes($k['nama_kelas'])) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="hapus">
            <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
            <button class="text-gray-400 hover:text-red-600" title="Hapus"><i class="fa-regular fa-trash-can"></i></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$daftar): ?>
      <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">
        Belum ada kelas pada periode ini. Tambahkan kelas, salin dari periode lain, atau impor siswa lengkap dengan kolom kelas.
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>