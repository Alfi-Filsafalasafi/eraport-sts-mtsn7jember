<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');
$id = (int)($_GET['id'] ?? 0);

$data = ['nis_lokal' => '', 'nis_nasional' => '', 'nama' => '', 'tanggal_lahir' => '', 'is_aktif' => 1];
if ($id) {
    $st = db()->prepare('SELECT id, nis_lokal, nis_nasional, nama, tanggal_lahir, is_aktif FROM siswa WHERE id = ?');
    $st->execute([$id]);
    $data = $st->fetch();
    if (!$data) { flash('Data siswa tidak ditemukan.'); redirect('admin/siswa.php'); }
    $data['nis_nasional'] = (string)$data['nis_nasional'];
    $data['tanggal_lahir'] = (string)$data['tanggal_lahir'];
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data['nis_lokal']     = trim($_POST['nis_lokal'] ?? '');
    $data['nis_nasional']  = trim($_POST['nis_nasional'] ?? '');
    $data['nama']          = trim(preg_replace('/\s+/', ' ', $_POST['nama'] ?? ''));
    $data['tanggal_lahir'] = trim($_POST['tanggal_lahir'] ?? '');
    if ($id) $data['is_aktif'] = isset($_POST['is_aktif']) ? 1 : 0;

    $tgl = DateTime::createFromFormat('!Y-m-d', $data['tanggal_lahir']);
    if (!preg_match('/^[A-Za-z0-9]{4,30}$/', $data['nis_lokal'])) {
        $error = 'NIS lokal hanya boleh huruf/angka, panjang 4 sampai 30 karakter.';
    } elseif ($data['nis_nasional'] !== '' && !preg_match('/^\d{10}$/', $data['nis_nasional'])) {
        $error = 'NISN harus 10 digit angka, atau dikosongkan.';
    } elseif ($data['nama'] === '' || mb_strlen($data['nama']) > 100) {
        $error = 'Nama wajib diisi dan maksimal 100 karakter.';
    } elseif ($data['tanggal_lahir'] !== '' && (!$tgl || $tgl->format('Y-m-d') !== $data['tanggal_lahir'])) {
        $error = 'Tanggal lahir tidak valid.';
    } else {
        $nisn = $data['nis_nasional'] !== '' ? $data['nis_nasional'] : null;
        $lahir = $data['tanggal_lahir'] !== '' ? $data['tanggal_lahir'] : null;
        try {
            if ($id) {
                db()->prepare('UPDATE siswa SET nis_lokal = ?, nis_nasional = ?, nama = ?, tanggal_lahir = ?, is_aktif = ? WHERE id = ?')
                    ->execute([$data['nis_lokal'], $nisn, $data['nama'], $lahir, $data['is_aktif'], $id]);
                flash('Data siswa diperbarui.', 'success');
            } else {
                db()->prepare('INSERT INTO siswa (nis_lokal, nis_nasional, nama, tanggal_lahir) VALUES (?, ?, ?, ?)')
                    ->execute([$data['nis_lokal'], $nisn, $data['nama'], $lahir]);
                flash('Siswa ditambahkan.', 'success');
            }
            redirect('admin/siswa.php');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $error = str_contains($ex->getMessage(), 'nis_nasional') ? 'NISN sudah dipakai siswa lain.' : 'NIS lokal sudah dipakai siswa lain.';
            } else {
                $error = 'Terjadi kesalahan database.';
            }
        }
    }
}

$inp = 'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none';
$title = $id ? 'Edit Siswa' : 'Tambah Siswa';
require __DIR__ . '/../app/partials/header.php';
?>
<a href="<?= url('admin/siswa.php') ?>" class="text-sm text-gray-500 hover:text-green-700">
  <i class="fa-solid fa-arrow-left mr-1"></i>Kembali ke daftar siswa
</a>
<h1 class="text-xl font-semibold mt-2 mb-4"><?= e($title) ?></h1>

<form method="post" class="bg-white border border-gray-200 rounded-lg p-6 max-w-lg space-y-4">
  <?= csrf_field() ?>
  <?php if ($error): ?>
    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"><?= e($error) ?></div>
  <?php endif; ?>

  <div>
    <label for="nis_lokal" class="block text-sm font-medium mb-1">NIS lokal</label>
    <input id="nis_lokal" name="nis_lokal" required value="<?= e($data['nis_lokal']) ?>" class="<?= $inp ?>">
  </div>

  <div>
    <label for="nis_nasional" class="block text-sm font-medium mb-1">NISN</label>
    <input id="nis_nasional" name="nis_nasional" inputmode="numeric" maxlength="10" value="<?= e($data['nis_nasional']) ?>" class="<?= $inp ?>">
    <p class="text-xs text-gray-500 mt-1">10 digit. Boleh dikosongkan.</p>
  </div>

  <div>
    <label for="nama" class="block text-sm font-medium mb-1">Nama lengkap</label>
    <input id="nama" name="nama" required maxlength="100" value="<?= e($data['nama']) ?>" class="<?= $inp ?>">
  </div>

  <div>
    <label for="tanggal_lahir" class="block text-sm font-medium mb-1">Tanggal lahir</label>
    <input id="tanggal_lahir" type="date" name="tanggal_lahir" value="<?= e($data['tanggal_lahir']) ?>" class="<?= $inp ?>">
    <p class="text-xs text-gray-500 mt-1">Dipakai wali sebagai verifikasi saat membuka rapor.</p>
  </div>

  <?php if ($id): ?>
  <label class="flex items-center gap-2 text-sm">
    <input type="checkbox" name="is_aktif" value="1" <?= $data['is_aktif'] ? 'checked' : '' ?>
           class="rounded border-gray-300 text-green-700 focus:ring-green-600">
    Siswa aktif
  </label>
  <?php endif; ?>

  <div class="flex gap-3 pt-2">
    <button class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">Simpan</button>
    <a href="<?= url('admin/siswa.php') ?>" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
  </div>
</form>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
