<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/kelas_helper.php';
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
$data = ['tingkat' => 7, 'rombel' => '', 'wali_kelas_id' => ''];

if ($id) {
    $st = db()->prepare('SELECT * FROM kelas WHERE id = ?');
    $st->execute([$id]);
    $k = $st->fetch();
    if (!$k) { flash('Kelas tidak ditemukan.'); redirect('admin/kelas.php'); }
    $pid = (int)$k['periode_id'];
    if ($k['status_rapor'] === 'final') {
        flash('Kelas ini sudah final dan terkunci, jadi tidak bisa diubah.');
        redirect('admin/kelas.php?periode=' . $pid);
    }
    $data = [
        'tingkat' => (int)$k['tingkat'],
        'rombel' => pecah_nama_kelas($k['nama_kelas']) ?? '',
        'wali_kelas_id' => $k['wali_kelas_id'] ? (string)$k['wali_kelas_id'] : '',
    ];
} else {
    $pid = (int)($_GET['periode'] ?? 0);
}

$st = db()->prepare('SELECT * FROM periode WHERE id = ?');
$st->execute([$pid]);
$periode = $st->fetch();
if (!$periode) { flash('Periode tidak ditemukan.'); redirect('admin/kelas.php'); }

// Guru yang sudah menjadi wali kelas lain pada periode ini
$st = db()->prepare('SELECT wali_kelas_id, nama_kelas FROM kelas
                     WHERE periode_id = ? AND wali_kelas_id IS NOT NULL AND id <> ?');
$st->execute([$pid, $id]);
$waliPakai = [];
foreach ($st->fetchAll() as $r) $waliPakai[(int)$r['wali_kelas_id']] = $r['nama_kelas'];

$guru = db()->query('SELECT id, nip, nama FROM users WHERE is_aktif = 1 ORDER BY nama')->fetchAll();
$idGuru = array_map('intval', array_column($guru, 'id'));

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data['tingkat'] = (int)($_POST['tingkat'] ?? 0);
    $data['rombel']  = strtoupper(trim($_POST['rombel'] ?? ''));
    $wali = (int)($_POST['wali_kelas_id'] ?? 0);
    $data['wali_kelas_id'] = $wali ? (string)$wali : '';

    if (!isset(ROMAWI[$data['tingkat']])) {
        $error = 'Tingkat harus 7, 8, atau 9.';
    } elseif (!preg_match('/^[A-Z0-9]{1,2}$/', $data['rombel'])) {
        $error = 'Rombel diisi 1 sampai 2 huruf/angka, misalnya A atau 1.';
    } elseif ($wali && !in_array($wali, $idGuru, true)) {
        $error = 'Wali kelas tidak valid.';
    } elseif ($wali && isset($waliPakai[$wali])) {
        $error = 'Guru ini sudah menjadi wali kelas ' . $waliPakai[$wali] . ' pada periode ini.';
    } else {
        $nama = nama_kelas($data['tingkat'], $data['rombel']);
        try {
            if ($id) {
                db()->prepare('UPDATE kelas SET nama_kelas = ?, tingkat = ?, wali_kelas_id = ? WHERE id = ?')
                    ->execute([$nama, $data['tingkat'], $wali ?: null, $id]);
                flash("Kelas $nama diperbarui.", 'success');
            } else {
                db()->prepare("INSERT INTO kelas (periode_id, nama_kelas, tingkat, fase, wali_kelas_id) VALUES (?, ?, ?, 'D', ?)")
                    ->execute([$pid, $nama, $data['tingkat'], $wali ?: null]);
                flash("Kelas $nama ditambahkan.", 'success');
            }
            redirect('admin/kelas.php?periode=' . $pid);
        } catch (PDOException $ex) {
            $error = $ex->getCode() === '23000' ? "Kelas $nama sudah ada pada periode ini." : 'Terjadi kesalahan database.';
        }
    }
}

$inp = 'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none';
$title = $id ? 'Edit Kelas' : 'Tambah Kelas';
require __DIR__ . '/../app/partials/header.php';
?>
<a href="<?= url('admin/kelas.php?periode=' . $pid) ?>" class="text-sm text-gray-500 hover:text-green-700">
  <i class="fa-solid fa-arrow-left mr-1"></i>Kembali ke daftar kelas
</a>
<h1 class="text-xl font-semibold mt-2 mb-1"><?= e($title) ?></h1>
<p class="text-sm text-gray-500 mb-4"><?= e(label_periode($periode)) ?></p>

<form method="post" class="bg-white border border-gray-200 rounded-lg p-6 max-w-lg space-y-4">
  <?= csrf_field() ?>
  <?php if ($error): ?>
    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"><?= e($error) ?></div>
  <?php endif; ?>

  <div class="grid grid-cols-2 gap-4">
    <div>
      <label for="tingkat" class="block text-sm font-medium mb-1">Tingkat</label>
      <select id="tingkat" name="tingkat" class="<?= $inp ?> bg-white">
        <?php foreach (ROMAWI as $t => $r): ?>
          <option value="<?= $t ?>" <?= $data['tingkat'] === $t ? 'selected' : '' ?>><?= $t ?> (<?= $r ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="rombel" class="block text-sm font-medium mb-1">Rombel</label>
      <input id="rombel" name="rombel" required maxlength="2" value="<?= e($data['rombel']) ?>" placeholder="A" class="<?= $inp ?> uppercase">
    </div>
  </div>
  <p class="text-sm text-gray-600">Nama kelas: <strong id="preview">-</strong> &middot; Fase D</p>

  <div>
    <label for="wali" class="block text-sm font-medium mb-1">Wali kelas</label>
    <select id="wali" name="wali_kelas_id" class="<?= $inp ?> bg-white">
      <option value="">Belum diatur</option>
      <?php foreach ($guru as $g): $gid = (int)$g['id']; $dipakai = isset($waliPakai[$gid]); ?>
        <option value="<?= $gid ?>" <?= $data['wali_kelas_id'] === (string)$gid ? 'selected' : '' ?> <?= $dipakai ? 'disabled' : '' ?>>
          <?= e($g['nama']) ?><?= $dipakai ? ' (wali ' . e($waliPakai[$gid]) . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="flex gap-3 pt-2">
    <button class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">Simpan</button>
    <a href="<?= url('admin/kelas.php?periode=' . $pid) ?>" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
  </div>
</form>
<script>
  (function () {
    const romawi = {7: 'VII', 8: 'VIII', 9: 'IX'};
    const t = document.getElementById('tingkat'), r = document.getElementById('rombel'), p = document.getElementById('preview');
    function perbarui() { p.textContent = ((romawi[t.value] || t.value) + ' ' + r.value.toUpperCase()).trim(); }
    t.addEventListener('change', perbarui);
    r.addEventListener('input', perbarui);
    perbarui();
  })();
</script>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>