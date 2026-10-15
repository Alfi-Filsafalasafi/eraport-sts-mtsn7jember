<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT k.*, p.tahun_ajaran, p.semester, u.nama AS walas
                     FROM kelas k
                     JOIN periode p ON p.id = k.periode_id
                     LEFT JOIN users u ON u.id = k.wali_kelas_id
                     WHERE k.id = ?');
$st->execute([$id]);
$kelas = $st->fetch();
if (!$kelas) { flash('Kelas tidak ditemukan.'); redirect('admin/kelas.php'); }
$pid = (int)$kelas['periode_id'];
$final = $kelas['status_rapor'] === 'final';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();
    if ($final) {
        flash('Kelas ini sudah final dan terkunci.');
    } else {
        $aksi = $_POST['aksi'] ?? '';
        $ambil = fn(string $k) => array_slice(
            array_values(array_unique(array_filter(array_map('intval', (array)($_POST[$k] ?? [])), fn($v) => $v > 0))), 0, 200);

        if ($aksi === 'tambah') {
            $sid = $ambil('tambah');
            if (!$sid) {
                flash('Pilih minimal satu siswa.');
            } else {
                $ins = $pdo->prepare('INSERT INTO anggota_kelas (periode_id, kelas_id, siswa_id)
                                      SELECT ?, ?, s.id FROM siswa s
                                      WHERE s.id = ? AND s.is_aktif = 1
                                        AND NOT EXISTS (SELECT 1 FROM anggota_kelas a WHERE a.periode_id = ? AND a.siswa_id = s.id)');
                $n = 0;
                foreach ($sid as $s) { $ins->execute([$pid, $id, $s, $pid]); $n += $ins->rowCount(); }
                flash("$n siswa ditambahkan ke kelas.", $n ? 'success' : 'error');
            }
        } elseif ($aksi === 'keluarkan' || $aksi === 'pindah') {
            $sid = $ambil('anggota');
            if (!$sid) {
                flash('Pilih minimal satu siswa.');
            } else {
                $in = implode(',', array_fill(0, count($sid), '?'));
                if ($aksi === 'keluarkan') {
                    $st = $pdo->prepare("DELETE FROM anggota_kelas WHERE kelas_id = ? AND siswa_id IN ($in)");
                    $st->execute([$id, ...$sid]);
                    flash($st->rowCount() . ' siswa dikeluarkan dari kelas.', 'success');
                } else {
                    $target = (int)($_POST['target'] ?? 0);
                    $cek = $pdo->prepare("SELECT 1 FROM kelas WHERE id = ? AND periode_id = ? AND id <> ? AND status_rapor = 'draft'");
                    $cek->execute([$target, $pid, $id]);
                    if (!$cek->fetchColumn()) {
                        flash('Kelas tujuan tidak valid atau sudah final.');
                    } else {
                        $st = $pdo->prepare("UPDATE anggota_kelas SET kelas_id = ? WHERE kelas_id = ? AND siswa_id IN ($in)");
                        $st->execute([$target, $id, ...$sid]);
                        flash($st->rowCount() . ' siswa dipindahkan.', 'success');
                    }
                }
            }
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Anggota kelas
$st = db()->prepare('SELECT s.id, s.nis_lokal, s.nis_nasional, s.nama, s.is_aktif
                     FROM anggota_kelas ak JOIN siswa s ON s.id = ak.siswa_id
                     WHERE ak.kelas_id = ? ORDER BY s.nama');
$st->execute([$id]);
$anggota = $st->fetchAll();

$targetList = [];
$kandidat = [];
$totalKandidat = 0;
$q = trim($_GET['q'] ?? '');

if (!$final) {
    $st = db()->prepare("SELECT id, nama_kelas FROM kelas
                         WHERE periode_id = ? AND id <> ? AND status_rapor = 'draft' ORDER BY tingkat, nama_kelas");
    $st->execute([$pid, $id]);
    $targetList = $st->fetchAll();

    $like = '%' . $q . '%';
    $cond = 's.is_aktif = 1
             AND NOT EXISTS (SELECT 1 FROM anggota_kelas a WHERE a.periode_id = ? AND a.siswa_id = s.id)
             AND (s.nama LIKE ? OR s.nis_lokal LIKE ?)';
    $st = db()->prepare("SELECT COUNT(*) FROM siswa s WHERE $cond");
    $st->execute([$pid, $like, $like]);
    $totalKandidat = (int)$st->fetchColumn();

    $st = db()->prepare("SELECT s.id, s.nis_lokal, s.nama FROM siswa s WHERE $cond ORDER BY s.nama LIMIT 100");
    $st->execute([$pid, $like, $like]);
    $kandidat = $st->fetchAll();
}

$inp = 'rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none';
$cb = 'rounded border-gray-300 text-green-700 focus:ring-green-600';
$title = 'Anggota Kelas ' . $kelas['nama_kelas'];
require __DIR__ . '/../app/partials/header.php';
?>
<a href="<?= url('admin/kelas.php?periode=' . $pid) ?>" class="text-sm text-gray-500 hover:text-green-700">
  <i class="fa-solid fa-arrow-left mr-1"></i>Kembali ke daftar kelas
</a>
<h1 class="text-xl font-semibold mt-2">Kelas <?= e($kelas['nama_kelas']) ?></h1>
<p class="text-sm text-gray-500 mb-4">
  Semester <?= e($kelas['semester']) ?> <?= e($kelas['tahun_ajaran']) ?> &middot;
  Wali kelas: <?= $kelas['walas'] ? e($kelas['walas']) : 'belum diatur' ?>
</p>

<?php if ($final): ?>
<div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-lock mr-1"></i>Rapor kelas ini sudah final, jadi daftar siswanya terkunci.
</div>
<?php endif; ?>

<h2 class="font-semibold mb-2">Anggota kelas (<?= count($anggota) ?>)</h2>
<form method="post" class="mb-8">
  <?= csrf_field() ?>
  <?php if (!$final && $anggota): ?>
  <div class="flex flex-wrap items-center gap-3 mb-2 text-sm">
    <span id="hitungAnggota" class="text-gray-500">0 dipilih</span>
    <button type="submit" name="aksi" value="keluarkan" class="rounded-md border border-red-300 bg-white text-red-700 hover:bg-red-50 px-3 py-1.5"
            onclick="return aksi('anggota[]', 'Keluarkan {n} siswa dari kelas ini? Nilai dan ketidakhadiran mereka di kelas ini ikut terhapus.')">
      <i class="fa-solid fa-user-minus mr-1"></i>Keluarkan
    </button>
    <?php if ($targetList): ?>
    <span class="text-gray-400">atau</span>
    <select name="target" id="target" class="<?= $inp ?> bg-white py-1.5">
      <option value="">Pindahkan ke...</option>
      <?php foreach ($targetList as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['nama_kelas']) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" name="aksi" value="pindah" class="rounded-md border border-gray-300 bg-white hover:bg-gray-50 px-3 py-1.5"
            onclick="if (!document.getElementById('target').value) { alert('Pilih kelas tujuan dulu.'); return false; } return aksi('anggota[]', 'Pindahkan {n} siswa ke kelas tujuan?')">
      <i class="fa-solid fa-right-left mr-1"></i>Pindahkan
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-green-50 text-green-900 text-left">
        <tr>
          <?php if (!$final): ?>
          <th class="px-4 py-2 w-10"><input type="checkbox" data-semua="anggota[]" data-hitung="hitungAnggota" aria-label="Pilih semua" class="<?= $cb ?>"></th>
          <?php endif; ?>
          <th class="px-4 py-2 font-medium">NIS Lokal</th>
          <th class="px-4 py-2 font-medium">NISN</th>
          <th class="px-4 py-2 font-medium">Nama</th>
          <th class="px-4 py-2 font-medium">Status</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
      <?php foreach ($anggota as $a): ?>
        <tr class="<?= $a['is_aktif'] ? '' : 'text-gray-400' ?>">
          <?php if (!$final): ?>
          <td class="px-4 py-2"><input type="checkbox" name="anggota[]" value="<?= (int)$a['id'] ?>" class="<?= $cb ?>"></td>
          <?php endif; ?>
          <td class="px-4 py-2 whitespace-nowrap"><?= e($a['nis_lokal']) ?></td>
          <td class="px-4 py-2 whitespace-nowrap"><?= e($a['nis_nasional'] ?? '-') ?></td>
          <td class="px-4 py-2"><?= e($a['nama']) ?></td>
          <td class="px-4 py-2"><?= $a['is_aktif'] ? 'Aktif' : 'Nonaktif' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$anggota): ?>
        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Kelas ini belum berisi siswa.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</form>

<?php if (!$final): ?>
<h2 class="font-semibold mb-1">Tambah siswa</h2>
<p class="text-sm text-gray-500 mb-3">Hanya siswa aktif yang belum punya kelas pada periode ini (<?= $totalKandidat ?> siswa).</p>

<form method="get" class="mb-3 flex gap-2 max-w-md">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input name="q" value="<?= e($q) ?>" placeholder="Cari nama atau NIS" class="<?= $inp ?> flex-1">
  <button class="rounded-md border border-gray-300 bg-white hover:bg-gray-50 text-sm font-medium px-4">Cari</button>
</form>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="aksi" value="tambah">
  <div class="flex items-center gap-3 mb-2 text-sm">
    <span id="hitungTambah" class="text-gray-500">0 dipilih</span>
    <button type="submit" class="rounded-md bg-green-700 hover:bg-green-800 text-white px-3 py-1.5"
            onclick="return aksi('tambah[]', 'Tambahkan {n} siswa ke kelas <?= e(addslashes($kelas['nama_kelas'])) ?>?')">
      <i class="fa-solid fa-user-plus mr-1"></i>Tambahkan ke kelas
    </button>
  </div>
  <div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-green-50 text-green-900 text-left">
        <tr>
          <th class="px-4 py-2 w-10"><input type="checkbox" data-semua="tambah[]" data-hitung="hitungTambah" aria-label="Pilih semua" class="<?= $cb ?>"></th>
          <th class="px-4 py-2 font-medium">NIS Lokal</th>
          <th class="px-4 py-2 font-medium">Nama</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
      <?php foreach ($kandidat as $s): ?>
        <tr>
          <td class="px-4 py-2"><input type="checkbox" name="tambah[]" value="<?= (int)$s['id'] ?>" class="<?= $cb ?>"></td>
          <td class="px-4 py-2 whitespace-nowrap"><?= e($s['nis_lokal']) ?></td>
          <td class="px-4 py-2"><?= e($s['nama']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$kandidat): ?>
        <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">
          <?= $q !== '' ? 'Tidak ada siswa yang cocok.' : 'Semua siswa aktif sudah punya kelas.' ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalKandidat > 100): ?>
    <p class="text-xs text-gray-500 mt-2">Menampilkan 100 dari <?= $totalKandidat ?> siswa. Gunakan pencarian untuk mempersempit.</p>
  <?php endif; ?>
</form>
<?php endif; ?>

<script>
  (function () {
    const kotak = n => Array.from(document.querySelectorAll('input[name="' + n + '"]'));
    document.querySelectorAll('[data-semua]').forEach(function (h) {
      const n = h.dataset.semua, label = document.getElementById(h.dataset.hitung);
      function perbarui() {
        const c = kotak(n).filter(x => x.checked).length;
        label.textContent = c + ' dipilih';
        h.checked = c > 0 && c === kotak(n).length;
        h.indeterminate = c > 0 && c < kotak(n).length;
      }
      h.addEventListener('change', () => { kotak(n).forEach(x => x.checked = h.checked); perbarui(); });
      kotak(n).forEach(x => x.addEventListener('change', perbarui));
    });
    window.aksi = function (nama, pesan) {
      const c = kotak(nama).filter(x => x.checked).length;
      if (!c) { alert('Pilih minimal satu siswa.'); return false; }
      return confirm(pesan.replace('{n}', c));
    };
  })();
</script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>