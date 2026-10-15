<?php
require __DIR__ . '/app/bootstrap.php';
require_login();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $lama  = $_POST['lama'] ?? '';
    $baru  = $_POST['baru'] ?? '';
    $ulang = $_POST['ulang'] ?? '';

    $st = db()->prepare('SELECT password FROM users WHERE id = ?');
    $st->execute([user()['id']]);
    $hash = (string)$st->fetchColumn();

    if (!password_verify($lama, $hash)) {
        $error = 'Password lama salah.';
    } elseif (strlen($baru) < 6) {
        $error = 'Password baru minimal 6 karakter.';
    } elseif ($baru === '123' || $baru === $lama) {
        $error = 'Password baru harus berbeda dari password lama dan tidak boleh 123.';
    } elseif ($baru !== $ulang) {
        $error = 'Konfirmasi password tidak sama.';
    } else {
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash($baru, PASSWORD_DEFAULT), user()['id']]);
        session_regenerate_id(true);
        flash('Password berhasil diganti.', 'success');
        redirect('ganti_password.php');
    }
}

$inp = 'w-full rounded-md border border-gray-300 pl-9 pr-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none';
$title = 'Ganti Password';
require __DIR__ . '/app/partials/header.php';
?>
<h1 class="text-xl font-semibold mb-1">Ganti Password</h1>
<p class="text-sm text-gray-500 mb-4">Akun: <?= e(user()['nama']) ?> (NIP <?= e(user()['nip']) ?>)</p>

<form method="post" class="bg-white border border-gray-200 rounded-lg p-6 max-w-md space-y-4">
  <?= csrf_field() ?>
  <?php if ($error): ?>
    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"><?= e($error) ?></div>
  <?php endif; ?>

  <?php foreach ([['lama', 'Password lama', 'current-password'], ['baru', 'Password baru', 'new-password'], ['ulang', 'Ulangi password baru', 'new-password']] as [$n, $label, $ac]): ?>
  <div>
    <label for="<?= $n ?>" class="block text-sm font-medium mb-1"><?= $label ?></label>
    <div class="relative">
      <i class="fa-solid fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
      <input id="<?= $n ?>" name="<?= $n ?>" type="password" required autocomplete="<?= $ac ?>" class="<?= $inp ?>">
    </div>
    <?php if ($n === 'baru'): ?><p class="text-xs text-gray-500 mt-1">Minimal 6 karakter.</p><?php endif; ?>
  </div>
  <?php endforeach; ?>

  <button class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">Simpan password</button>
</form>
<?php require __DIR__ . '/app/partials/footer.php'; ?>
