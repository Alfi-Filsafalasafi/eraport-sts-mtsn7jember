<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/xlsx_reader.php';
require_role('admin');

$hasil = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();
    try {
        $f = $_FILES['berkas'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Pilih file terlebih dahulu (maksimal 2 MB).');
        if ($f['size'] > 2 * 1024 * 1024) throw new RuntimeException('Ukuran file maksimal 2 MB.');
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) throw new RuntimeException('Format file harus .xlsx atau .csv.');

        $baris = baca_tabel($f['tmp_name'], $ext);
        if (count($baris) > 1001) throw new RuntimeException('Maksimal 1000 baris per impor.');

        $perbarui = ($_POST['mode'] ?? '') === 'perbarui';
        $ada  = array_change_key_case($pdo->query('SELECT nip, id FROM users')->fetchAll(PDO::FETCH_KEY_PAIR), CASE_LOWER);
        $hash = password_hash('123', PASSWORD_DEFAULT);
        $ins  = $pdo->prepare("INSERT INTO users (nip, nama, password, role) VALUES (?, ?, ?, 'guru')");
        $upd  = $pdo->prepare('UPDATE users SET nama = ? WHERE id = ?');

        $hasil = ['baru' => 0, 'diperbarui' => 0, 'dilewati' => 0, 'galat' => []];
        $terlihat = [];

        $pdo->beginTransaction();
        foreach ($baris as $no => $r) {
            $nip  = $r[0] ?? '';
            $nama = trim(preg_replace('/\s+/', ' ', $r[1] ?? ''));
            if ($nip === '' && $nama === '') continue;           // baris kosong
            if (strtolower($nip) === 'nip') continue;            // baris judul

            $kunci = strtolower($nip);
            if (!preg_match('/^[A-Za-z0-9]{4,30}$/', $nip)) {
                $hasil['galat'][] = "Baris $no: NIP \"$nip\" tidak valid. Pastikan kolom NIP berformat Teks, bukan Angka.";
            } elseif ($nama === '' || mb_strlen($nama) > 100) {
                $hasil['galat'][] = "Baris $no: nama kosong atau lebih dari 100 karakter.";
            } elseif (isset($terlihat[$kunci])) {
                $hasil['galat'][] = "Baris $no: NIP $nip muncul dua kali di file (sebelumnya di baris {$terlihat[$kunci]}).";
            } else {
                $terlihat[$kunci] = $no;
                if (isset($ada[$kunci])) {
                    if ($perbarui) { $upd->execute([$nama, $ada[$kunci]]); $hasil['diperbarui']++; }
                    else           { $hasil['dilewati']++; }
                } else {
                    $ins->execute([$nip, $nama, $hash]);
                    $hasil['baru']++;
                }
            }
        }
        $pdo->commit();
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $hasil = null;
        flash($ex->getMessage());
        redirect('admin/guru_import.php');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $hasil = null;
        flash('Impor gagal karena kesalahan database. Tidak ada data yang tersimpan.');
        redirect('admin/guru_import.php');
    }
}

$title = 'Impor Guru';
require __DIR__ . '/../app/partials/header.php';
?>
<a href="<?= url('admin/guru.php') ?>" class="text-sm text-gray-500 hover:text-green-700">
  <i class="fa-solid fa-arrow-left mr-1"></i>Kembali ke daftar guru
</a>
<h1 class="text-xl font-semibold mt-2 mb-4">Impor Guru dari Excel</h1>

<?php if ($hasil): ?>
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-6">
  <h2 class="font-semibold mb-2">Hasil impor</h2>
  <ul class="text-sm space-y-1">
    <li><i class="fa-solid fa-circle-check text-green-700 mr-2"></i><?= $hasil['baru'] ?> guru baru ditambahkan (password awal 123)</li>
    <?php if ($hasil['diperbarui']): ?><li><i class="fa-solid fa-pen text-green-700 mr-2"></i><?= $hasil['diperbarui'] ?> nama guru diperbarui</li><?php endif; ?>
    <?php if ($hasil['dilewati']): ?><li><i class="fa-solid fa-forward text-gray-500 mr-2"></i><?= $hasil['dilewati'] ?> dilewati karena NIP sudah terdaftar</li><?php endif; ?>
    <?php if ($hasil['galat']): ?><li><i class="fa-solid fa-triangle-exclamation text-red-600 mr-2"></i><?= count($hasil['galat']) ?> baris bermasalah dan tidak diimpor</li><?php endif; ?>
  </ul>
  <?php if ($hasil['galat']): ?>
    <ul class="mt-3 max-h-60 overflow-y-auto rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800 space-y-1">
      <?php foreach (array_slice($hasil['galat'], 0, 50) as $g): ?><li><?= e($g) ?></li><?php endforeach; ?>
      <?php if (count($hasil['galat']) > 50): ?><li>... dan <?= count($hasil['galat']) - 50 ?> baris lainnya.</li><?php endif; ?>
    </ul>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-2">
  <form method="post" enctype="multipart/form-data" class="bg-white border border-gray-200 rounded-lg p-6 space-y-4">
    <?= csrf_field() ?>
    <div>
      <label for="berkas" class="block text-sm font-medium mb-1">File Excel (.xlsx) atau CSV</label>
      <input id="berkas" type="file" name="berkas" accept=".xlsx,.csv" required
             class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-green-700 file:px-3 file:py-2 file:text-white hover:file:bg-green-800">
    </div>
    <fieldset class="text-sm space-y-2">
      <legend class="font-medium mb-1">Jika NIP sudah terdaftar</legend>
      <label class="flex items-center gap-2"><input type="radio" name="mode" value="lewati" checked class="text-green-700 focus:ring-green-600"> Lewati, data lama tidak diubah</label>
      <label class="flex items-center gap-2"><input type="radio" name="mode" value="perbarui" class="text-green-700 focus:ring-green-600"> Perbarui namanya</label>
    </fieldset>
    <button class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">
      <i class="fa-solid fa-file-import mr-1"></i>Impor sekarang
    </button>
  </form>

  <div class="bg-white border border-gray-200 rounded-lg p-6 text-sm space-y-3">
    <h2 class="font-semibold">Panduan</h2>
    <ol class="list-decimal pl-5 space-y-1.5 text-gray-700">
      <li>Unduh template di bawah. Kolom NIP sudah berformat Teks.</li>
      <li>Isi kolom A dengan NIP dan kolom B dengan nama lengkap beserta gelar. Satu guru per baris.</li>
      <li>Bagi yang belum punya NIP, isi dengan 1005 dan seterusnya.</li>
      <li>Unggah file di sebelah kiri. Semua guru baru mendapat password 123.</li>
    </ol>
    <p class="text-gray-500">Maksimal 1000 baris dan 2 MB per impor. Kalau memakai file sendiri, ubah format kolom NIP menjadi Teks sebelum mengetik, supaya angka NIP tidak dibulatkan Excel.</p>
    <a href="<?= url('admin/guru_template.php') ?>" class="inline-block rounded-md border border-gray-300 px-3 py-2 hover:bg-gray-50">
      <i class="fa-regular fa-file-excel mr-1 text-green-700"></i>Unduh template Excel
    </a>
  </div>
</div>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
