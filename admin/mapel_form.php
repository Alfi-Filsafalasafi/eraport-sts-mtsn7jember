<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/kelas_helper.php';
require __DIR__ . '/../app/mapel_helper.php';
require_role('admin');

$id = (int)($_GET['id'] ?? 0);

$periodeList = daftar_periode();
$petaPeriode = [];
foreach ($periodeList as $p) $petaPeriode[(int)$p['id']] = $p;
$pid = (int)($_GET['periode'] ?? 0);
if (!isset($petaPeriode[$pid])) {
    $a = periode_aktif();
    $pid = $a ? (int)$a['id'] : (int)($periodeList[0]['id'] ?? 0);
}
$kembali = 'admin/mapel.php' . ($pid ? '?periode=' . $pid : '');

$headers = db()->query('SELECT id, nama FROM mapel WHERE is_header = 1 ORDER BY urutan, id')->fetchAll();
$idHeader = array_map('intval', array_column($headers, 'id'));

$data = ['jenis' => 'biasa', 'nama' => '', 'parent_id' => 0, 'is_aktif' => 1, 'tingkat' => [7, 8, 9]];
if ($id) {
    $st = db()->prepare('SELECT * FROM mapel WHERE id = ?');
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) { flash('Mapel tidak ditemukan.'); redirect($kembali); }
    $data['jenis'] = $m['is_header'] ? 'grup' : ($m['parent_id'] !== null ? 'sub' : 'biasa');
    $data['nama'] = $m['nama'];
    $data['parent_id'] = (int)$m['parent_id'];
    $data['is_aktif'] = (int)$m['is_aktif'];
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$id) $data['jenis'] = in_array($_POST['jenis'] ?? '', ['biasa', 'sub', 'grup'], true) ? $_POST['jenis'] : 'biasa';
    $data['nama'] = trim(preg_replace('/\s+/', ' ', $_POST['nama'] ?? ''));
    $data['parent_id'] = $data['jenis'] === 'sub' ? (int)($_POST['parent_id'] ?? 0) : 0;
    if ($id) $data['is_aktif'] = isset($_POST['is_aktif']) ? 1 : 0;
    if (!$id) {
        $data['tingkat'] = array_values(array_filter(array_map('intval', (array)($_POST['tingkat'] ?? [])),
                                                      fn($t) => isset(TINGKAT_MAPEL[$t])));
    }

    $st = db()->prepare('SELECT COUNT(*) FROM mapel WHERE LOWER(nama) = LOWER(?) AND id <> ? AND COALESCE(parent_id, 0) = ?');
    $st->execute([$data['nama'], $id, $data['parent_id']]);
    $kembar = (int)$st->fetchColumn() > 0;

    if ($data['nama'] === '' || mb_strlen($data['nama']) > 150) {
        $error = 'Nama mapel wajib diisi dan maksimal 150 karakter.';
    } elseif ($data['jenis'] === 'sub' && !in_array($data['parent_id'], $idHeader, true)) {
        $error = 'Pilih grup induk untuk sub-mapel ini.';
    } elseif ($kembar) {
        $error = 'Sudah ada mapel dengan nama yang sama di tempat ini.';
    } elseif (!$id && $data['jenis'] !== 'grup' && !$data['tingkat']) {
        $error = 'Pilih minimal satu tingkat.';
    } elseif (!$id && $data['jenis'] !== 'grup' && !$pid) {
        $error = 'Belum ada periode. Buat periode dulu di menu Kelola Periode.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $parent = $data['jenis'] === 'sub' ? $data['parent_id'] : null;

            if ($id) {
                $pdo->prepare('UPDATE mapel SET nama = ?, parent_id = ? WHERE id = ?')->execute([$data['nama'], $parent, $id]);
                $pdo->prepare('UPDATE mapel SET is_aktif = ? WHERE id = ? OR parent_id = ?')->execute([$data['is_aktif'], $id, $id]);
                if ($pid) sinkron_header($pid);
                flash('Mapel diperbarui.', 'success');
            } else {
                $urut = (int)$pdo->query('SELECT COALESCE(MAX(urutan), 0) + 1 FROM mapel')->fetchColumn();
                $pdo->prepare('INSERT INTO mapel (parent_id, nama, is_header, urutan) VALUES (?, ?, ?, ?)')
                    ->execute([$parent, $data['nama'], $data['jenis'] === 'grup' ? 1 : 0, $urut]);
                $baru = (int)$pdo->lastInsertId();
                if ($data['jenis'] !== 'grup') {
                    $ins = $pdo->prepare('INSERT INTO mapel_tingkat (periode_id, tingkat, mapel_id) VALUES (?, ?, ?)');
                    foreach ($data['tingkat'] as $t) $ins->execute([$pid, $t, $baru]);
                    sinkron_header($pid);
                }
                flash('Mapel ditambahkan.', 'success');
            }
            rapikan_urutan();
            $pdo->commit();
            redirect($kembali);
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Terjadi kesalahan database.';
        }
    }
}

$jenisLabel = ['biasa' => 'Mapel biasa', 'sub' => 'Sub-mapel', 'grup' => 'Grup (judul)'];
$inp = 'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none';
$title = $id ? 'Edit Mapel' : 'Tambah Mapel';
require __DIR__ . '/../app/partials/header.php';
?>
<a href="<?= url($kembali) ?>" class="text-sm text-gray-500 hover:text-green-700">
  <i class="fa-solid fa-arrow-left mr-1"></i>Kembali ke daftar mapel
</a>
<h1 class="text-xl font-semibold mt-2 mb-4"><?= e($title) ?></h1>

<form method="post" id="formMapel" data-jenis="<?= e($data['jenis']) ?>" data-edit="<?= $id ? '1' : '0' ?>"
      class="bg-white border border-gray-200 rounded-lg p-6 max-w-lg space-y-4">
  <?= csrf_field() ?>
  <?php if ($error): ?>
    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if (!$id): ?>
  <fieldset class="space-y-2 text-sm">
    <legend class="font-medium mb-1">Jenis</legend>
    <label class="flex gap-2"><input type="radio" name="jenis" value="biasa" <?= $data['jenis'] === 'biasa' ? 'checked' : '' ?> class="mt-0.5 text-green-700 focus:ring-green-600">
      <span><strong>Mapel biasa</strong> <span class="text-gray-500">- contoh: Matematika, Coding</span></span></label>
    <label class="flex gap-2"><input type="radio" name="jenis" value="sub" <?= $data['jenis'] === 'sub' ? 'checked' : '' ?> class="mt-0.5 text-green-700 focus:ring-green-600">
      <span><strong>Sub-mapel</strong> <span class="text-gray-500">- di dalam grup, contoh: Fikih di bawah Pendidikan Agama Islam</span></span></label>
    <label class="flex gap-2"><input type="radio" name="jenis" value="grup" <?= $data['jenis'] === 'grup' ? 'checked' : '' ?> class="mt-0.5 text-green-700 focus:ring-green-600">
      <span><strong>Grup</strong> <span class="text-gray-500">- hanya judul pengelompok, tanpa nilai sendiri</span></span></label>
  </fieldset>
  <?php else: ?>
  <p class="text-sm text-gray-600">Jenis: <strong><?= e($jenisLabel[$data['jenis']]) ?></strong></p>
  <?php endif; ?>

  <div>
    <label for="nama" class="block text-sm font-medium mb-1">Nama mapel</label>
    <input id="nama" name="nama" required maxlength="150" value="<?= e($data['nama']) ?>" class="<?= $inp ?>">
  </div>

  <div id="blokInduk">
    <label for="parent_id" class="block text-sm font-medium mb-1">Grup induk</label>
    <select id="parent_id" name="parent_id" class="<?= $inp ?> bg-white">
      <option value="0">Pilih grup...</option>
      <?php foreach ($headers as $h): ?>
        <option value="<?= (int)$h['id'] ?>" <?= $data['parent_id'] === (int)$h['id'] ? 'selected' : '' ?>><?= e($h['nama']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if (!$id): ?>
  <fieldset id="blokTingkat" class="text-sm">
    <legend class="font-medium mb-1">Berlaku untuk tingkat</legend>
    <p class="text-xs text-gray-500 mb-2">Pada periode: <?= $pid ? e(label_periode($petaPeriode[$pid])) : '-' ?>. Bisa diubah lagi dari daftar mapel.</p>
    <div class="flex gap-4">
      <?php foreach (TINGKAT_MAPEL as $t => $r): ?>
        <label class="flex items-center gap-2">
          <input type="checkbox" name="tingkat[]" value="<?= $t ?>" <?= in_array($t, $data['tingkat'], true) ? 'checked' : '' ?>
                 class="rounded border-gray-300 text-green-700 focus:ring-green-600"> <?= $r ?>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <?php else: ?>
  <label class="flex items-center gap-2 text-sm">
    <input type="checkbox" name="is_aktif" value="1" <?= $data['is_aktif'] ? 'checked' : '' ?>
           class="rounded border-gray-300 text-green-700 focus:ring-green-600">
    Mapel aktif (tampil di rapor)
  </label>
  <?php endif; ?>

  <div class="flex gap-3 pt-2">
    <button class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">Simpan</button>
    <a href="<?= url($kembali) ?>" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
  </div>
</form>

<script>
  (function () {
    const f = document.getElementById('formMapel');
    const induk = document.getElementById('blokInduk');
    const tingkat = document.getElementById('blokTingkat');
    function jenis() {
      const r = f.querySelector('input[name="jenis"]:checked');
      return r ? r.value : f.dataset.jenis;
    }
    function ubah() {
      induk.style.display = jenis() === 'sub' ? '' : 'none';
      if (tingkat) tingkat.style.display = jenis() === 'grup' ? 'none' : '';
    }
    f.querySelectorAll('input[name="jenis"]').forEach(r => r.addEventListener('change', ubah));
    ubah();
  })();
</script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>