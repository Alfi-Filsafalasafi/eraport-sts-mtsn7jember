<?php
require __DIR__ . '/head.php';
$_u = user();
$_p = periode_aktif();
$_cur = basename($_SERVER['SCRIPT_NAME']);

// [url, label, ikon, daftar file yang menandai menu ini sedang aktif]
$_menu = [['index.php', 'Beranda', 'fa-house', ['index.php']]];
if ($_u['role'] === 'admin') {
    $_menu[] = ['admin/periode.php', 'Kelola Periode', 'fa-calendar-days', ['periode.php']];
    $_menu[] = ['admin/guru.php', 'Kelola Guru', 'fa-chalkboard-user', ['guru.php', 'guru_form.php', 'guru_import.php']];
    $_menu[] = ['admin/siswa.php', 'Kelola Siswa', 'fa-user-graduate', ['siswa.php', 'siswa_form.php', 'siswa_import.php']];
    $_menu[] = ['admin/kelas.php', 'Kelola Kelas', 'fa-school', ['kelas.php', 'kelas_form.php', 'kelas_anggota.php', 'kelas_salin.php']];
    $_menu[] = ['admin/mapel.php', 'Kelola Mapel', 'fa-book', ['mapel.php', 'mapel_form.php']];
}
$_nav = fn(bool $on) => $on ? 'bg-green-900 text-white font-medium' : 'text-green-50 hover:bg-green-700';
?>
<div class="min-h-screen lg:flex">

  <!-- Bilah atas khusus layar kecil -->
  <div class="lg:hidden sticky top-0 z-30 h-14 px-4 flex items-center gap-3 bg-green-800 text-white">
    <button id="menuBtn" type="button" aria-label="Buka menu"><i class="fa-solid fa-bars text-lg"></i></button>
    <span class="font-semibold"><?= e(cfg('app_name')) ?></span>
  </div>
  <div id="overlay" class="hidden fixed inset-0 z-40 bg-black/40 lg:hidden"></div>

  <aside id="sidebar"
         class="fixed inset-y-0 left-0 z-50 w-64 shrink-0 flex flex-col bg-green-800 text-white
                -translate-x-full transition-transform lg:translate-x-0 lg:sticky lg:top-0 lg:h-screen">
    <div class="px-4 py-4 flex items-center gap-3 border-b border-green-700">
      <img src="<?= url('assets/img/logo.png') ?>" alt="" class="h-10 w-auto">
      <div class="leading-tight">
        <div class="font-semibold"><?= e(cfg('app_name')) ?></div>
        <div class="text-xs text-green-200">MTs Negeri 7 Jember</div>
      </div>
    </div>

    <?php if ($_p): ?>
    <div class="mx-3 mt-3 rounded-md bg-green-900 px-3 py-2 text-sm">
      <div class="text-xs text-green-200">Periode aktif</div>
      <div class="font-medium">Semester <?= e($_p['semester']) ?> <?= e($_p['tahun_ajaran']) ?></div>
    </div>
    <?php endif; ?>

    <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-1 text-sm">
      <?php foreach ($_menu as [$_path, $_label, $_icon, $_files]): ?>
        <a href="<?= url($_path) ?>" class="flex items-center gap-3 rounded-md px-3 py-2 <?= $_nav(in_array($_cur, $_files, true)) ?>">
          <i class="fa-solid <?= $_icon ?> w-4 text-center"></i><?= $_label ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="border-t border-green-700 px-3 py-3 text-sm space-y-1">
      <div class="px-3 pb-2">
        <div class="font-medium truncate" title="<?= e($_u['nama']) ?>"><?= e($_u['nama']) ?></div>
        <div class="text-xs text-green-200"><?= $_u['role'] === 'admin' ? 'Admin' : 'Guru' ?></div>
      </div>
      <a href="<?= url('ganti_password.php') ?>" class="flex items-center gap-3 rounded-md px-3 py-2 <?= $_nav($_cur === 'ganti_password.php') ?>">
        <i class="fa-solid fa-key w-4 text-center"></i>Ganti Password
      </a>
      <a href="<?= url('logout.php') ?>" class="flex items-center gap-3 rounded-md px-3 py-2 text-green-50 hover:bg-green-700">
        <i class="fa-solid fa-right-from-bracket w-4 text-center"></i>Keluar
      </a>
    </div>
  </aside>

  <div class="flex-1 min-w-0">
    <main class="max-w-5xl mx-auto px-4 sm:px-6 py-6">
<?php if ($_f = flash()): ?>
      <div class="mb-4 rounded-md border px-4 py-3 text-sm <?= $_f['type'] === 'success' ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800' ?>">
        <?= e($_f['msg']) ?>
      </div>
<?php endif; ?>
