<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $pdo = db();

    try {
        if ($aksi === 'tambah') {
            $ta  = trim($_POST['tahun_ajaran'] ?? '');
            $sem = $_POST['semester'] ?? '';
            $tgl = ($_POST['tanggal_cetak'] ?? '') ?: null;

            if (!preg_match('~^(\d{4})/(\d{4})$~', $ta, $m) || (int)$m[2] !== (int)$m[1] + 1) {
                throw new RuntimeException('Tahun ajaran harus berformat 2024/2025.');
            }
            if (!in_array($sem, ['Ganjil', 'Genap'], true)) {
                throw new RuntimeException('Semester tidak valid.');
            }

            $pdo->beginTransaction();
            $prev = (int)$pdo->query('SELECT MAX(id) FROM periode')->fetchColumn();
            $pdo->prepare('INSERT INTO periode (tahun_ajaran, semester, tanggal_cetak) VALUES (?, ?, ?)')
                ->execute([$ta, $sem, $tgl]);
            $baru = (int)$pdo->lastInsertId();

            if ($prev) {   // salin aturan mapel per tingkat dari periode sebelumnya
                $pdo->prepare('INSERT INTO mapel_tingkat (periode_id, tingkat, mapel_id)
                               SELECT ?, tingkat, mapel_id FROM mapel_tingkat WHERE periode_id = ?')
                    ->execute([$baru, $prev]);
            }
            $pdo->commit();
            flash("Periode $sem $ta ditambahkan.", 'success');

        } elseif ($aksi === 'aktifkan') {
            $pdo->beginTransaction();
            $pdo->exec('UPDATE periode SET is_aktif = 0');
            $pdo->prepare('UPDATE periode SET is_aktif = 1 WHERE id = ?')->execute([$id]);
            $pdo->commit();
            flash('Periode aktif diperbarui.', 'success');

        } elseif ($aksi === 'simpan_tanggal') {
            $tgl = ($_POST['tanggal_cetak'] ?? '') ?: null;
            $pdo->prepare('UPDATE periode SET tanggal_cetak = ? WHERE id = ?')->execute([$tgl, $id]);
            flash('Tanggal cetak disimpan.', 'success');

        } elseif ($aksi === 'hapus') {
            $st = $pdo->prepare('SELECT is_aktif, (SELECT COUNT(*) FROM kelas WHERE periode_id = periode.id) AS jml
                                 FROM periode WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch();
            if (!$r) throw new RuntimeException('Periode tidak ditemukan.');
            if ($r['is_aktif']) throw new RuntimeException('Periode aktif tidak bisa dihapus.');
            if ($r['jml'] > 0) throw new RuntimeException('Periode ini masih punya kelas, jadi tidak bisa dihapus.');
            $pdo->prepare('DELETE FROM periode WHERE id = ?')->execute([$id]);
            flash('Periode dihapus.', 'success');
        }
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($ex->getMessage());
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($ex->getCode() === '23000' ? 'Periode tersebut sudah ada.' : 'Terjadi kesalahan database.');
    }
    redirect('admin/periode.php');
}

$daftar = db()->query(
    'SELECT p.*, (SELECT COUNT(*) FROM kelas k WHERE k.periode_id = p.id) AS jml_kelas
     FROM periode p ORDER BY p.tahun_ajaran DESC, p.semester DESC'
)->fetchAll();

$inp = 'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none';
$title = 'Kelola Periode';
require __DIR__ . '/../app/partials/header.php';
?>
<h1 class="text-xl font-semibold mb-1">Kelola Periode</h1>
<p class="text-sm text-gray-500 mb-6">Hanya satu periode yang aktif. Guru dan wali melihat data periode aktif.</p>

<form method="post" class="bg-white border border-gray-200 rounded-lg p-4 mb-6 grid gap-4 sm:grid-cols-4 items-end">
  <?= csrf_field() ?>
  <input type="hidden" name="aksi" value="tambah">
  <div>
    <label class="block text-sm font-medium mb-1" for="ta">Tahun ajaran</label>
    <input id="ta" name="tahun_ajaran" required placeholder="2025/2026" pattern="\d{4}/\d{4}" class="<?= $inp ?>">
  </div>
  <div>
    <label class="block text-sm font-medium mb-1" for="sem">Semester</label>
    <select id="sem" name="semester" class="<?= $inp ?>">
      <option>Ganjil</option><option>Genap</option>
    </select>
  </div>
  <div>
    <label class="block text-sm font-medium mb-1" for="tgl">Tanggal cetak rapor</label>
    <input id="tgl" type="date" name="tanggal_cetak" class="<?= $inp ?>">
  </div>
  <button class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">
    <i class="fa-solid fa-plus mr-1"></i>Tambah periode
  </button>
</form>

<div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-green-50 text-green-900 text-left">
      <tr>
        <th class="px-4 py-2 font-medium">Tahun ajaran</th>
        <th class="px-4 py-2 font-medium">Semester</th>
        <th class="px-4 py-2 font-medium">Tanggal cetak</th>
        <th class="px-4 py-2 font-medium">Kelas</th>
        <th class="px-4 py-2 font-medium">Status</th>
        <th class="px-4 py-2 font-medium text-right">Aksi</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
    <?php foreach ($daftar as $p): ?>
      <tr>
        <td class="px-4 py-2 font-medium"><?= e($p['tahun_ajaran']) ?></td>
        <td class="px-4 py-2"><?= e($p['semester']) ?></td>
        <td class="px-4 py-2">
          <form method="post" class="flex gap-2 items-center">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="simpan_tanggal">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="date" name="tanggal_cetak" value="<?= e($p['tanggal_cetak']) ?>"
                   class="rounded-md border border-gray-300 px-2 py-1 text-sm">
            <button class="text-gray-500 hover:text-green-700" title="Simpan tanggal"><i class="fa-regular fa-floppy-disk"></i></button>
          </form>
        </td>
        <td class="px-4 py-2"><?= (int)$p['jml_kelas'] ?></td>
        <td class="px-4 py-2">
          <?php if ($p['is_aktif']): ?>
            <span class="text-green-700"><i class="fa-solid fa-circle-check mr-1"></i>Aktif</span>
          <?php else: ?>
            <form method="post" onsubmit="return confirm('Jadikan periode ini sebagai periode aktif?')">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="aktifkan">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="text-green-700 hover:underline">Aktifkan</button>
            </form>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2 text-right">
          <?php if (!$p['is_aktif']): ?>
            <form method="post" class="inline" onsubmit="return confirm('Hapus periode ini?')">
              <?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="text-gray-400 hover:text-red-600" title="Hapus"><i class="fa-regular fa-trash-can"></i></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
