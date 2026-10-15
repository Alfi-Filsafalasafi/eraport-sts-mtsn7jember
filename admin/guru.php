<?php
require __DIR__ . '/../app/bootstrap.php';
require_role('admin');
$me = (int)user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'reset') {
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash('123', PASSWORD_DEFAULT), $id]);
        flash('Password berhasil direset ke 123.', 'success');
    } elseif ($aksi === 'toggle') {
        if ($id === $me) {
            flash('Anda tidak bisa menonaktifkan akun sendiri.');
        } else {
            db()->prepare('UPDATE users SET is_aktif = 1 - is_aktif WHERE id = ?')->execute([$id]);
            flash('Status akun diperbarui.', 'success');
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);   // kembali ke halaman & pencarian yang sama
    exit;
}

$q = trim($_GET['q'] ?? '');
$like = '%' . $q . '%';
$per = 25;

$st = db()->prepare('SELECT COUNT(*) FROM users WHERE nama LIKE ? OR nip LIKE ?');
$st->execute([$like, $like]);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$off = ($page - 1) * $per;

$st = db()->prepare("SELECT id, nip, nama, role, is_aktif FROM users
                     WHERE nama LIKE ? OR nip LIKE ? ORDER BY role, nama LIMIT $per OFFSET $off");
$st->execute([$like, $like]);
$daftar = $st->fetchAll();

$title = 'Kelola Guru';
require __DIR__ . '/../app/partials/header.php';
?>
<div class="flex flex-wrap items-end justify-between gap-3 mb-4">
  <div>
    <h1 class="text-xl font-semibold">Kelola Guru</h1>
    <p class="text-sm text-gray-500"><?= $total ?> akun. Password awal guru baru adalah 123.</p>
  </div>
  <div class="flex gap-2">
    <a href="<?= url('admin/guru_import.php') ?>" class="rounded-md border border-gray-300 bg-white hover:bg-gray-50 text-sm font-medium py-2 px-4">
      <i class="fa-regular fa-file-excel mr-1 text-green-700"></i>Impor Excel
    </a>
    <a href="<?= url('admin/guru_form.php') ?>" class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">
      <i class="fa-solid fa-plus mr-1"></i>Tambah guru
    </a>
  </div>
</div>

<form method="get" class="mb-4 max-w-sm relative">
  <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
  <input name="q" value="<?= e($q) ?>" placeholder="Cari nama atau NIP"
         class="w-full rounded-md border border-gray-300 pl-9 pr-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none">
</form>

<div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-green-50 text-green-900 text-left">
      <tr>
        <th class="px-4 py-2 font-medium">NIP</th>
        <th class="px-4 py-2 font-medium">Nama</th>
        <th class="px-4 py-2 font-medium">Peran</th>
        <th class="px-4 py-2 font-medium">Status</th>
        <th class="px-4 py-2 font-medium text-right">Aksi</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
    <?php foreach ($daftar as $g): ?>
      <tr class="<?= $g['is_aktif'] ? '' : 'text-gray-400' ?>">
        <td class="px-4 py-2 whitespace-nowrap"><?= e($g['nip']) ?></td>
        <td class="px-4 py-2"><?= e($g['nama']) ?></td>
        <td class="px-4 py-2"><?= $g['role'] === 'admin' ? 'Admin' : 'Guru' ?></td>
        <td class="px-4 py-2"><?= $g['is_aktif'] ? 'Aktif' : 'Nonaktif' ?></td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
          <a href="<?= url('admin/guru_form.php?id=' . (int)$g['id']) ?>" class="text-gray-500 hover:text-green-700 mr-3" title="Edit">
            <i class="fa-solid fa-pen"></i>
          </a>
          <form method="post" class="inline" onsubmit="return confirm('Reset password <?= e(addslashes($g['nama'])) ?> ke 123?')">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="reset">
            <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
            <button class="text-gray-500 hover:text-green-700 mr-3" title="Reset password ke 123"><i class="fa-solid fa-key"></i></button>
          </form>
          <?php if ((int)$g['id'] !== $me): ?>
          <form method="post" class="inline" onsubmit="return confirm('Ubah status akun ini?')">
            <?= csrf_field() ?>
            <input type="hidden" name="aksi" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
            <button class="text-gray-500 hover:text-red-600" title="<?= $g['is_aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
              <i class="fa-solid <?= $g['is_aktif'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$daftar): ?>
      <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Tidak ada guru yang cocok dengan pencarian.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
<div class="flex items-center justify-between mt-4 text-sm">
  <span class="text-gray-500">Halaman <?= $page ?> dari <?= $pages ?></span>
  <div class="flex gap-2">
    <?php if ($page > 1): ?>
      <a href="?q=<?= urlencode($q) ?>&page=<?= $page - 1 ?>" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 hover:bg-gray-50">Sebelumnya</a>
    <?php endif; ?>
    <?php if ($page < $pages): ?>
      <a href="?q=<?= urlencode($q) ?>&page=<?= $page + 1 ?>" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 hover:bg-gray-50">Berikutnya</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
