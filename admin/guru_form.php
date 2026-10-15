<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');
$me = (int)user()['id'];
$id = (int)($_GET['id'] ?? 0);

$data = ['nip' => '', 'nama' => '', 'role' => 'guru', 'is_aktif' => 1];
if ($id) {
    $st = db()->prepare('SELECT id, nip, nama, role, is_aktif FROM users WHERE id = ?');
    $st->execute([$id]);
    $data = $st->fetch();
    if (!$data) { flash('Data guru tidak ditemukan.'); redirect('admin/guru.php'); }
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data['nip']  = trim($_POST['nip'] ?? '');
    $data['nama'] = trim(preg_replace('/\s+/', ' ', $_POST['nama'] ?? ''));
    $data['role'] = in_array($_POST['role'] ?? '', ['admin', 'guru'], true) ? $_POST['role'] : 'guru';
    if ($id) $data['is_aktif'] = isset($_POST['is_aktif']) ? 1 : 0;
    if ($id === $me) { $data['role'] = 'admin'; $data['is_aktif'] = 1; }

    if (!preg_match('/^[A-Za-z0-9]{4,30}$/', $data['nip'])) {
        $error = 'NIP hanya boleh huruf/angka, panjang 4 sampai 30 karakter.';
    } elseif ($data['nama'] === '' || mb_strlen($data['nama']) > 100) {
        $error = 'Nama wajib diisi dan maksimal 100 karakter.';
    } else {
        try {
            if ($id) {
                db()->prepare('UPDATE users SET nip = ?, nama = ?, role = ?, is_aktif = ? WHERE id = ?')
                    ->execute([$data['nip'], $data['nama'], $data['role'], $data['is_aktif'], $id]);
                flash('Data guru diperbarui.', 'success');
            } else {
                db()->prepare('INSERT INTO users (nip, nama, password, role) VALUES (?, ?, ?, ?)')
                    ->execute([$data['nip'], $data['nama'], password_hash('123', PASSWORD_DEFAULT), $data['role']]);
                flash('Guru ditambahkan. Password awal: 123.', 'success');
            }
            redirect('admin/guru.php');
        } catch (PDOException $ex) {
            $error = $ex->getCode() === '23000' ? 'NIP sudah dipakai akun lain.' : 'Terjadi kesalahan database.';
        }
    }
}

$inp = 'w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none';
$title = $id ? 'Edit Guru' : 'Tambah Guru';
require __DIR__ . '/../app/partials/header.php';
?>
<a href="<?= url('admin/guru.php') ?>" class="text-sm text-gray-500 hover:text-green-700">
  <i class="fa-solid fa-arrow-left mr-1"></i>Kembali ke daftar guru
</a>
<h1 class="text-xl font-semibold mt-2 mb-4"><?= e($title) ?></h1>

<form method="post" class="bg-white border border-gray-200 rounded-lg p-6 max-w-lg space-y-4">
  <?= csrf_field() ?>
  <?php if ($error): ?>
    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"><?= e($error) ?></div>
  <?php endif; ?>

  <div>
    <label for="nip" class="block text-sm font-medium mb-1">NIP</label>
    <input id="nip" name="nip" required value="<?= e($data['nip']) ?>" class="<?= $inp ?>">
    <p class="text-xs text-gray-500 mt-1">NIP dipakai untuk login. Bagi yang belum punya NIP, pakai 1005 dan seterusnya.</p>
  </div>

  <div>
    <label for="nama" class="block text-sm font-medium mb-1">Nama lengkap dengan gelar</label>
    <input id="nama" name="nama" required maxlength="100" value="<?= e($data['nama']) ?>" class="<?= $inp ?>">
  </div>

  <div>
    <label for="role" class="block text-sm font-medium mb-1">Peran</label>
    <select id="role" name="role" class="<?= $inp ?>" <?= $id === $me ? 'disabled' : '' ?>>
      <option value="guru"  <?= $data['role'] === 'guru'  ? 'selected' : '' ?>>Guru</option>
      <option value="admin" <?= $data['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
    </select>
  </div>

  <?php if ($id): ?>
  <label class="flex items-center gap-2 text-sm">
    <input type="checkbox" name="is_aktif" value="1" <?= $data['is_aktif'] ? 'checked' : '' ?> <?= $id === $me ? 'disabled' : '' ?>
           class="rounded border-gray-300 text-green-700 focus:ring-green-600">
    Akun aktif (bisa login)
  </label>
  <?php endif; ?>

  <div class="flex gap-3 pt-2">
    <button class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">Simpan</button>
    <a href="<?= url('admin/guru.php') ?>" class="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
  </div>
</form>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
