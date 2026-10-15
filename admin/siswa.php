<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();

    if (isset($_POST['toggle'])) {
        $pdo->prepare('UPDATE siswa SET is_aktif = 1 - is_aktif WHERE id = ?')->execute([(int)$_POST['toggle']]);
        flash('Status siswa diperbarui.', 'success');

    } elseif (isset($_POST['bulk'])) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0)));
        $ids = array_slice($ids, 0, 200);
        $mode = $_POST['bulk'];

        if (!$ids) {
            flash('Pilih minimal satu siswa.');
        } elseif ($mode === 'nonaktif') {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE siswa SET is_aktif = 0 WHERE id IN ($in)")->execute($ids);
            flash(count($ids) . ' siswa dinonaktifkan.', 'success');
        } elseif ($mode === 'hapus') {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT DISTINCT ak.siswa_id FROM anggota_kelas ak
                                 JOIN kelas k ON k.id = ak.kelas_id
                                 WHERE k.status_rapor = 'final' AND ak.siswa_id IN ($in)");
            $st->execute($ids);
            $terkunci = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $hapus = array_values(array_diff($ids, $terkunci));

            if ($hapus) {
                $in = implode(',', array_fill(0, count($hapus), '?'));
                $pdo->prepare("DELETE FROM siswa WHERE id IN ($in)")->execute($hapus);
            }
            $pesan = count($hapus) . ' siswa dihapus.';
            if ($terkunci) $pesan .= ' ' . count($terkunci) . ' siswa dilewati karena rapornya sudah final.';
            flash($pesan, $hapus ? 'success' : 'error');
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);   // kembali ke halaman, pencarian, dan filter yang sama
    exit;
}

$periodeAktif = periode_aktif();
$pid = $periodeAktif ? (int)$periodeAktif['id'] : 0;

$q = trim($_GET['q'] ?? '');
$kelasF = (string)($_GET['kelas'] ?? '');
if ($kelasF !== 'none') $kelasF = (ctype_digit($kelasF) && (int)$kelasF > 0) ? (string)(int)$kelasF : '';

$like = '%' . $q . '%';
$per = 25;

$from = 'FROM siswa s
         LEFT JOIN anggota_kelas ak ON ak.siswa_id = s.id AND ak.periode_id = ?
         LEFT JOIN kelas k ON k.id = ak.kelas_id';
$where = '(s.nama LIKE ? OR s.nis_lokal LIKE ? OR s.nis_nasional LIKE ?)';
$params = [$pid, $like, $like, $like];
if ($kelasF === 'none') {
    $where .= ' AND ak.id IS NULL';
} elseif ($kelasF !== '') {
    $where .= ' AND ak.kelas_id = ?';
    $params[] = (int)$kelasF;
}

$st = db()->prepare("SELECT COUNT(*) $from WHERE $where");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$off = ($page - 1) * $per;

$st = db()->prepare("SELECT s.id, s.nis_lokal, s.nis_nasional, s.nama, s.tanggal_lahir, s.is_aktif, k.nama_kelas
                     $from WHERE $where ORDER BY s.nama LIMIT $per OFFSET $off");
$st->execute($params);
$daftar = $st->fetchAll();

$kelasList = [];
if ($pid) {
    $st = db()->prepare('SELECT k.id, k.nama_kelas,
                                (SELECT COUNT(*) FROM anggota_kelas a WHERE a.kelas_id = k.id) AS jml
                         FROM kelas k WHERE k.periode_id = ? ORDER BY k.tingkat, k.nama_kelas');
    $st->execute([$pid]);
    $kelasList = $st->fetchAll();
}

$tanpaTgl = (int)db()->query('SELECT COUNT(*) FROM siswa WHERE is_aktif = 1 AND tanggal_lahir IS NULL')->fetchColumn();
$tanpaKelas = 0;
if ($pid) {
    $st = db()->prepare('SELECT COUNT(*) FROM siswa s WHERE s.is_aktif = 1
                         AND NOT EXISTS (SELECT 1 FROM anggota_kelas a WHERE a.siswa_id = s.id AND a.periode_id = ?)');
    $st->execute([$pid]);
    $tanpaKelas = (int)$st->fetchColumn();
}

$link = fn(array $x = []) => '?' . http_build_query(array_merge(['q' => $q, 'kelas' => $kelasF], $x));
$inp = 'rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none';

$title = 'Kelola Siswa';
require __DIR__ . '/../app/partials/header.php';
?>
<div class="flex flex-wrap items-end justify-between gap-3 mb-4">
  <div>
    <h1 class="text-xl font-semibold">Kelola Siswa</h1>
    <p class="text-sm text-gray-500"><?= $total ?> siswa<?= ($q !== '' || $kelasF !== '') ? ' sesuai pencarian/filter' : '' ?>.</p>
  </div>
  <div class="flex gap-2">
    <a href="<?= url('admin/siswa_import.php') ?>" class="rounded-md border border-gray-300 bg-white hover:bg-gray-50 text-sm font-medium py-2 px-4">
      <i class="fa-regular fa-file-excel mr-1 text-green-700"></i>Impor Excel
    </a>
    <a href="<?= url('admin/siswa_form.php') ?>" class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">
      <i class="fa-solid fa-plus mr-1"></i>Tambah siswa
    </a>
  </div>
</div>

<?php if ($tanpaTgl > 0): ?>
<div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-circle-info mr-1"></i>
  <?= $tanpaTgl ?> siswa aktif belum punya tanggal lahir. Tanggal lahir dipakai wali untuk verifikasi saat membuka rapor.
</div>
<?php endif; ?>
<?php if ($tanpaKelas > 0): ?>
<div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-circle-info mr-1"></i>
  <?= $tanpaKelas ?> siswa aktif belum punya kelas pada periode aktif.
  <a href="?kelas=none" class="underline">Tampilkan</a>
</div>
<?php endif; ?>

<form method="get" class="mb-4 flex flex-wrap gap-2">
  <div class="relative w-full sm:w-72">
    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
    <input name="q" value="<?= e($q) ?>" placeholder="Cari nama, NIS, atau NISN" class="<?= $inp ?> w-full pl-9">
  </div>
  <?php if ($kelasList): ?>
  <select name="kelas" onchange="this.form.submit()" class="<?= $inp ?> bg-white">
    <option value="">Semua kelas</option>
    <?php foreach ($kelasList as $kl): ?>
      <option value="<?= (int)$kl['id'] ?>" <?= $kelasF === (string)$kl['id'] ? 'selected' : '' ?>>
        <?= e($kl['nama_kelas']) ?> (<?= (int)$kl['jml'] ?>)
      </option>
    <?php endforeach; ?>
    <option value="none" <?= $kelasF === 'none' ? 'selected' : '' ?>>Belum punya kelas</option>
  </select>
  <?php endif; ?>
  <button class="rounded-md border border-gray-300 bg-white hover:bg-gray-50 text-sm font-medium px-4">Cari</button>
  <?php if ($q !== '' || $kelasF !== ''): ?>
    <a href="<?= url('admin/siswa.php') ?>" class="self-center text-sm text-gray-500 hover:text-green-700">Reset</a>
  <?php endif; ?>
</form>

<form method="post" id="bulkForm">
  <?= csrf_field() ?>

  <div class="flex flex-wrap items-center gap-3 mb-2 text-sm">
    <span id="jmlDipilih" class="text-gray-500">0 dipilih</span>
    <button type="submit" name="bulk" value="nonaktif" onclick="return aksiBulk(this)"
            class="rounded-md border border-gray-300 bg-white hover:bg-gray-50 px-3 py-1.5">
      <i class="fa-solid fa-user-slash mr-1"></i>Nonaktifkan
    </button>
    <button type="submit" name="bulk" value="hapus" onclick="return aksiBulk(this)"
            class="rounded-md border border-red-300 bg-white text-red-700 hover:bg-red-50 px-3 py-1.5">
      <i class="fa-regular fa-trash-can mr-1"></i>Hapus
    </button>
  </div>

  <div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-green-50 text-green-900 text-left">
        <tr>
          <th class="px-4 py-2 w-10">
            <input type="checkbox" id="pilihSemua" aria-label="Pilih semua di halaman ini"
                   class="rounded border-gray-300 text-green-700 focus:ring-green-600">
          </th>
          <th class="px-4 py-2 font-medium">NIS Lokal</th>
          <th class="px-4 py-2 font-medium">NISN</th>
          <th class="px-4 py-2 font-medium">Nama</th>
          <th class="px-4 py-2 font-medium">Kelas</th>
          <th class="px-4 py-2 font-medium">Tanggal lahir</th>
          <th class="px-4 py-2 font-medium">Status</th>
          <th class="px-4 py-2 font-medium text-right">Aksi</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
      <?php foreach ($daftar as $s): ?>
        <tr class="<?= $s['is_aktif'] ? '' : 'text-gray-400' ?>">
          <td class="px-4 py-2">
            <input type="checkbox" name="ids[]" value="<?= (int)$s['id'] ?>" aria-label="Pilih <?= e($s['nama']) ?>"
                   class="rounded border-gray-300 text-green-700 focus:ring-green-600">
          </td>
          <td class="px-4 py-2 whitespace-nowrap"><?= e($s['nis_lokal']) ?></td>
          <td class="px-4 py-2 whitespace-nowrap"><?= e($s['nis_nasional'] ?? '-') ?></td>
          <td class="px-4 py-2"><?= e($s['nama']) ?></td>
          <td class="px-4 py-2 whitespace-nowrap"><?= e($s['nama_kelas'] ?? '-') ?></td>
          <td class="px-4 py-2 whitespace-nowrap"><?= $s['tanggal_lahir'] ? e(date('d-m-Y', strtotime($s['tanggal_lahir']))) : '-' ?></td>
          <td class="px-4 py-2"><?= $s['is_aktif'] ? 'Aktif' : 'Nonaktif' ?></td>
          <td class="px-4 py-2 text-right whitespace-nowrap">
            <a href="<?= url('admin/siswa_form.php?id=' . (int)$s['id']) ?>" class="text-gray-500 hover:text-green-700 mr-3" title="Edit">
              <i class="fa-solid fa-pen"></i>
            </a>
            <button type="submit" name="toggle" value="<?= (int)$s['id'] ?>" onclick="return confirm('Ubah status siswa ini?')"
                    class="text-gray-500 hover:text-red-600" title="<?= $s['is_aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
              <i class="fa-solid <?= $s['is_aktif'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$daftar): ?>
        <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">
          <?= ($q !== '' || $kelasF !== '') ? 'Tidak ada siswa yang cocok dengan pencarian atau filter.' : 'Belum ada siswa. Tambahkan satu per satu atau impor dari Excel.' ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</form>

<?php if ($pages > 1): ?>
<div class="flex items-center justify-between mt-4 text-sm">
  <span class="text-gray-500">Halaman <?= $page ?> dari <?= $pages ?></span>
  <div class="flex gap-2">
    <?php if ($page > 1): ?>
      <a href="<?= e($link(['page' => $page - 1])) ?>" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 hover:bg-gray-50">Sebelumnya</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
      <a href="<?= e($link(['page' => $page + 1])) ?>" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 hover:bg-gray-50">Berikutnya</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
  (function () {
    const semua = document.getElementById('pilihSemua');
    const kotak = () => Array.from(document.querySelectorAll('input[name="ids[]"]'));
    function perbarui() {
      const n = kotak().filter(c => c.checked).length;
      document.getElementById('jmlDipilih').textContent = n + ' dipilih';
      semua.checked = n > 0 && n === kotak().length;
      semua.indeterminate = n > 0 && n < kotak().length;
    }
    semua.addEventListener('change', () => { kotak().forEach(c => c.checked = semua.checked); perbarui(); });
    kotak().forEach(c => c.addEventListener('change', perbarui));

    window.aksiBulk = function (btn) {
      const n = kotak().filter(c => c.checked).length;
      if (!n) { alert('Pilih minimal satu siswa.'); return false; }
      if (btn.value === 'hapus') {
        return confirm('Hapus permanen ' + n + ' siswa?\n\nData kelas, nilai, dan ketidakhadiran mereka ikut terhapus dan tidak bisa dikembalikan. Siswa di kelas yang sudah final akan dilewati.\n\nUntuk siswa yang hanya tidak aktif lagi, pakai Nonaktifkan.');
      }
      return confirm('Nonaktifkan ' + n + ' siswa?');
    };
  })();
</script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>