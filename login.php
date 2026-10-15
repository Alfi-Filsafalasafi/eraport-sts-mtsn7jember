<?php
require __DIR__ . '/app/bootstrap.php';
if (user()) redirect('index.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nip = trim($_POST['nip'] ?? '');
    if (login_guru($nip, $_POST['password'] ?? '')) redirect('index.php');
    $error = 'NIP atau password salah.';
}
$title = 'Masuk';
require __DIR__ . '/app/partials/head.php';
?>
<div class="min-h-screen flex items-center justify-center px-4">
  <div class="w-full max-w-sm">
    <div class="text-center mb-6">
      <img src="<?= url('assets/img/logo.png') ?>" alt="Logo MTsN 7 Jember"
           class="mx-auto mb-3 h-20 w-auto">
      <h1 class="text-xl font-semibold">E-Rapor Sumatif Tengah Semester</h1>
      <p class="text-sm text-gray-500 mt-1">MTs Negeri 7 Jember</p>
    </div>

    <form method="post" class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
      <?= csrf_field() ?>
      <?php if ($error): ?>
        <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"><?= e($error) ?></div>
      <?php endif; ?>

      <div>
        <label for="nip" class="block text-sm font-medium mb-1">NIP</label>
        <div class="relative">
          <i class="fa-regular fa-id-card absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
          <input id="nip" name="nip" type="text" inputmode="numeric" required autofocus autocomplete="username"
                 value="<?= e($_POST['nip'] ?? '') ?>"
                 class="w-full rounded-md border border-gray-300 pl-9 pr-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none">
        </div>
      </div>

      <div>
        <label for="password" class="block text-sm font-medium mb-1">Password</label>
        <div class="relative">
          <i class="fa-solid fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
          <input id="password" name="password" type="password" required autocomplete="current-password"
                 class="w-full rounded-md border border-gray-300 pl-9 pr-10 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none">
          <button type="button" id="toggle" aria-label="Tampilkan password"
                  class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
            <i class="fa-regular fa-eye"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="w-full rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2.5">
        Masuk
      </button>
    </form>
  </div>
</div>
<script>
  document.getElementById('toggle').addEventListener('click', function () {
    const i = document.getElementById('password');
    const show = i.type === 'password';
    i.type = show ? 'text' : 'password';
    this.innerHTML = '<i class="fa-regular ' + (show ? 'fa-eye-slash' : 'fa-eye') + '"></i>';
  });
</script>
</body>
</html>
