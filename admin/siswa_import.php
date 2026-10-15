<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/xlsx_reader.php';
require_role('admin');

$periode = periode_aktif();

/** Mengembalikan 'Y-m-d', null jika kosong, atau false jika tidak valid. */
function parse_tanggal(string $v): string|null|false {
    $v = trim($v);
    if ($v === '') return null;
    if (is_numeric($v) && (float)$v > 10000 && (float)$v < 80000) {      // tanggal serial Excel
        return (new DateTime('1899-12-30'))->modify('+' . (int)$v . ' days')->format('Y-m-d');
    }
    if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $v, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('~^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$~', $v, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } else {
        return false;
    }
    if (!checkdate($mo, $d, $y) || $y < 1980 || $y > (int)date('Y')) return false;
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** Mengembalikan [tingkat, 'VII A'], null jika kosong, atau false jika tidak dikenali. */
function parse_kelas(string $v): array|null|false {
    $v = strtoupper(trim($v));
    if ($v === '') return null;
    $v = preg_replace('/^KELAS\s*/', '', $v);
    if (!preg_match('/^(VIII|VII|IX|7|8|9)[\s\-_.]*([A-Z0-9]{1,2})$/', $v, $m)) return false;
    $tingkat = ['VII' => 7, 'VIII' => 8, 'IX' => 9][$m[1]] ?? (int)$m[1];
    $romawi = [7 => 'VII', 8 => 'VIII', 9 => 'IX'][$tingkat];
    return [$tingkat, $romawi . ' ' . $m[2]];
}

$hasil = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();
    try {
        if (!$periode) throw new RuntimeException('Belum ada periode aktif. Aktifkan satu periode di menu Kelola Periode dulu.');
        $pid = (int)$periode['id'];

        $f = $_FILES['berkas'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Pilih file terlebih dahulu (maksimal 2 MB).');
        if ($f['size'] > 2 * 1024 * 1024) throw new RuntimeException('Ukuran file maksimal 2 MB.');
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) throw new RuntimeException('Format file harus .xlsx atau .csv.');

        $baris = baca_tabel($f['tmp_name'], $ext);
        if (count($baris) > 2001) throw new RuntimeException('Maksimal 2000 baris per impor.');

        $perbarui = ($_POST['mode'] ?? '') === 'perbarui';
        $pindah   = ($_POST['kelas_mode'] ?? '') === 'pindah';

        $adaNis  = array_change_key_case($pdo->query('SELECT nis_lokal, id FROM siswa')->fetchAll(PDO::FETCH_KEY_PAIR), CASE_LOWER);
        $adaNisn = $pdo->query('SELECT nis_nasional, id FROM siswa WHERE nis_nasional IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);

        // Kelas pada periode aktif
        $st = $pdo->prepare('SELECT id, nama_kelas, status_rapor FROM kelas WHERE periode_id = ?');
        $st->execute([$pid]);
        $kelasNama = [];
        $kelasInfo = [];
        foreach ($st->fetchAll() as $k) {
            $kelasNama[strtoupper($k['nama_kelas'])] = (int)$k['id'];
            $kelasInfo[(int)$k['id']] = $k;
        }

        // Keanggotaan kelas pada periode aktif: siswa_id => [id anggota, kelas_id]
        $st = $pdo->prepare('SELECT id, siswa_id, kelas_id FROM anggota_kelas WHERE periode_id = ?');
        $st->execute([$pid]);
        $anggota = [];
        foreach ($st->fetchAll() as $a) $anggota[(int)$a['siswa_id']] = ['id' => (int)$a['id'], 'kelas' => (int)$a['kelas_id']];

        $insSiswa   = $pdo->prepare('INSERT INTO siswa (nis_lokal, nis_nasional, nama, tanggal_lahir) VALUES (?, ?, ?, ?)');
        $updSiswa   = $pdo->prepare('UPDATE siswa SET nama = ?, nis_nasional = COALESCE(?, nis_nasional),
                                     tanggal_lahir = COALESCE(?, tanggal_lahir) WHERE id = ?');
        $insKelas   = $pdo->prepare("INSERT INTO kelas (periode_id, nama_kelas, tingkat, fase) VALUES (?, ?, ?, 'D')");
        $insAnggota = $pdo->prepare('INSERT INTO anggota_kelas (periode_id, kelas_id, siswa_id) VALUES (?, ?, ?)');
        $updAnggota = $pdo->prepare('UPDATE anggota_kelas SET kelas_id = ? WHERE id = ?');

        $hasil = ['baru' => 0, 'diperbarui' => 0, 'dilewati' => 0, 'masuk' => 0, 'pindah' => 0, 'tetap' => 0,
                  'kelas_baru' => [], 'galat' => []];
        $lihatNis = [];
        $lihatNisn = [];

        $pdo->beginTransaction();
        foreach ($baris as $no => $r) {
            $nis     = $r[0] ?? '';
            $nisn    = $r[1] ?? '';
            $nama    = trim(preg_replace('/\s+/', ' ', $r[2] ?? ''));
            $tglIn   = $r[3] ?? '';
            $kelasIn = $r[4] ?? '';

            if ($nis === '' && $nisn === '' && $nama === '' && $tglIn === '' && $kelasIn === '') continue;   // baris kosong
            if (preg_match('/^nis/i', $nis) && !preg_match('/\d/', $nis)) continue;                           // baris judul

            $kunci   = strtolower($nis);
            $tgl     = parse_tanggal($tglIn);
            $kls     = parse_kelas($kelasIn);
            $idAda   = $adaNis[$kunci] ?? null;
            $nisnVal = $nisn !== '' ? $nisn : null;
            $err     = null;

            if (preg_match('/e\+/i', $nis . $nisn)) {
                $err = 'NIS/NISN berubah jadi notasi ilmiah. Ubah format kolom menjadi Teks lalu ketik ulang.';
            } elseif (!preg_match('/^[A-Za-z0-9]{4,30}$/', $nis)) {
                $err = "NIS lokal \"$nis\" tidak valid (huruf/angka, 4 sampai 30 karakter).";
            } elseif ($nisn !== '' && !preg_match('/^\d{10}$/', $nisn)) {
                $err = "NISN \"$nisn\" harus 10 digit angka.";
            } elseif ($nama === '' || mb_strlen($nama) > 100) {
                $err = 'Nama kosong atau lebih dari 100 karakter.';
            } elseif ($tgl === false) {
                $err = "Tanggal lahir \"$tglIn\" tidak valid. Gunakan 2012-05-17 atau 17/05/2012.";
            } elseif ($kls === false) {
                $err = "Kelas \"$kelasIn\" tidak dikenali. Gunakan format seperti VII A atau 7A (tingkat 7 sampai 9).";
            } elseif (isset($lihatNis[$kunci])) {
                $err = "NIS $nis muncul dua kali di file (sebelumnya di baris {$lihatNis[$kunci]}).";
            } elseif ($nisn !== '' && isset($lihatNisn[$nisn])) {
                $err = "NISN $nisn muncul dua kali di file (sebelumnya di baris {$lihatNisn[$nisn]}).";
            }

            // NISN bentrok dengan siswa lain di database (hanya relevan jika NISN akan ditulis)
            $tulisNisn = $nisnVal !== null && (!$idAda || $perbarui);
            if (!$err && $tulisNisn && isset($adaNisn[$nisnVal]) && (int)$adaNisn[$nisnVal] !== (int)($idAda ?? 0)) {
                $err = "NISN $nisn sudah dipakai siswa lain.";
            }

            // Kelas final terkunci
            $targetId = null;
            if (!$err && $kls) {
                $targetId = $kelasNama[strtoupper($kls[1])] ?? null;
                if ($targetId && ($kelasInfo[$targetId]['status_rapor'] ?? '') === 'final') {
                    $err = "Kelas {$kls[1]} sudah final, anggotanya tidak bisa diubah.";
                }
                $cur = $idAda ? ($anggota[$idAda] ?? null) : null;
                if (!$err && $cur && $cur['kelas'] !== $targetId && $pindah
                    && ($kelasInfo[$cur['kelas']]['status_rapor'] ?? '') === 'final') {
                    $err = "Siswa ini ada di kelas {$kelasInfo[$cur['kelas']]['nama_kelas']} yang sudah final, jadi tidak bisa dipindahkan.";
                }
            }

            if ($err) { $hasil['galat'][] = "Baris $no: $err"; continue; }

            $lihatNis[$kunci] = $no;
            if ($nisn !== '') $lihatNisn[$nisn] = $no;

            // 1) Data siswa
            if ($idAda) {
                $idSiswa = (int)$idAda;
                if ($perbarui) { $updSiswa->execute([$nama, $nisnVal, $tgl, $idSiswa]); $hasil['diperbarui']++; }
                else           { $hasil['dilewati']++; }
            } else {
                $insSiswa->execute([$nis, $nisnVal, $nama, $tgl]);
                $idSiswa = (int)$pdo->lastInsertId();
                $adaNis[$kunci] = $idSiswa;
                $hasil['baru']++;
            }
            if ($tulisNisn) $adaNisn[$nisnVal] = $idSiswa;

            // 2) Kelas
            if ($kls) {
                if (!$targetId) {
                    $insKelas->execute([$pid, $kls[1], $kls[0]]);
                    $targetId = (int)$pdo->lastInsertId();
                    $kelasNama[strtoupper($kls[1])] = $targetId;
                    $kelasInfo[$targetId] = ['id' => $targetId, 'nama_kelas' => $kls[1], 'status_rapor' => 'draft'];
                    $hasil['kelas_baru'][] = $kls[1];
                }
                $cur = $anggota[$idSiswa] ?? null;
                if (!$cur) {
                    $insAnggota->execute([$pid, $targetId, $idSiswa]);
                    $anggota[$idSiswa] = ['id' => (int)$pdo->lastInsertId(), 'kelas' => $targetId];
                    $hasil['masuk']++;
                } elseif ($cur['kelas'] === $targetId) {
                    // sudah ada di kelas yang sama, tidak ada perubahan
                } elseif ($pindah) {
                    $updAnggota->execute([$targetId, $cur['id']]);
                    $anggota[$idSiswa]['kelas'] = $targetId;
                    $hasil['pindah']++;
                } else {
                    $hasil['tetap']++;
                }
            }
        }
        $pdo->commit();
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($ex->getMessage());
        redirect('admin/siswa_import.php');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('Impor gagal karena kesalahan database. Tidak ada data yang tersimpan.');
        redirect('admin/siswa_import.php');
    }
}

$title = 'Impor Siswa';
require __DIR__ . '/../app/partials/header.php';
?>
<a href="<?= url('admin/siswa.php') ?>" class="text-sm text-gray-500 hover:text-green-700">
  <i class="fa-solid fa-arrow-left mr-1"></i>Kembali ke daftar siswa
</a>
<h1 class="text-xl font-semibold mt-2 mb-4">Impor Siswa dari Excel</h1>

<?php if (!$periode): ?>
<div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-circle-info mr-1"></i>Belum ada periode aktif. Aktifkan satu periode di menu Kelola Periode sebelum mengimpor.
</div>
<?php endif; ?>

<?php if ($hasil): ?>
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-6">
  <h2 class="font-semibold mb-2">Hasil impor</h2>
  <ul class="text-sm space-y-1">
    <li><i class="fa-solid fa-circle-check text-green-700 mr-2"></i><?= $hasil['baru'] ?> siswa baru ditambahkan</li>
    <?php if ($hasil['diperbarui']): ?><li><i class="fa-solid fa-pen text-green-700 mr-2"></i><?= $hasil['diperbarui'] ?> data siswa diperbarui</li><?php endif; ?>
    <?php if ($hasil['dilewati']): ?><li><i class="fa-solid fa-forward text-gray-500 mr-2"></i><?= $hasil['dilewati'] ?> siswa sudah terdaftar, datanya tidak diubah</li><?php endif; ?>
    <?php if ($hasil['masuk']): ?><li><i class="fa-solid fa-people-group text-green-700 mr-2"></i><?= $hasil['masuk'] ?> siswa dimasukkan ke kelas</li><?php endif; ?>
    <?php if ($hasil['pindah']): ?><li><i class="fa-solid fa-right-left text-green-700 mr-2"></i><?= $hasil['pindah'] ?> siswa dipindahkan kelas</li><?php endif; ?>
    <?php if ($hasil['tetap']): ?><li><i class="fa-solid fa-forward text-gray-500 mr-2"></i><?= $hasil['tetap'] ?> siswa sudah punya kelas lain, kelasnya tidak diubah</li><?php endif; ?>
    <?php if ($hasil['kelas_baru']): ?><li><i class="fa-solid fa-school text-green-700 mr-2"></i>Kelas baru dibuat: <?= e(implode(', ', $hasil['kelas_baru'])) ?>. Wali kelas diatur di menu Kelola Kelas.</li><?php endif; ?>
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
      <legend class="font-medium mb-1">Jika NIS lokal sudah terdaftar</legend>
      <label class="flex items-center gap-2"><input type="radio" name="mode" value="lewati" checked class="text-green-700 focus:ring-green-600"> Lewati, nama, NISN, dan tanggal lahir tidak diubah</label>
      <label class="flex items-center gap-2"><input type="radio" name="mode" value="perbarui" class="text-green-700 focus:ring-green-600"> Perbarui nama, NISN, dan tanggal lahir</label>
    </fieldset>
    <fieldset class="text-sm space-y-2">
      <legend class="font-medium mb-1">Jika siswa sudah punya kelas di periode ini</legend>
      <label class="flex items-center gap-2"><input type="radio" name="kelas_mode" value="biarkan" checked class="text-green-700 focus:ring-green-600"> Biarkan di kelas lama</label>
      <label class="flex items-center gap-2"><input type="radio" name="kelas_mode" value="pindah" class="text-green-700 focus:ring-green-600"> Pindahkan ke kelas di file</label>
    </fieldset>
    <button class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4" <?= $periode ? '' : 'disabled' ?>>
      <i class="fa-solid fa-file-import mr-1"></i>Impor sekarang
    </button>
  </form>

  <div class="bg-white border border-gray-200 rounded-lg p-6 text-sm space-y-3">
    <h2 class="font-semibold">Panduan</h2>
    <p class="text-gray-700">
      Siswa dimasukkan ke kelas pada periode aktif<?php if ($periode): ?>
      (Semester <?= e($periode['semester']) ?> <?= e($periode['tahun_ajaran']) ?>)<?php endif; ?>.
    </p>
    <ol class="list-decimal pl-5 space-y-1.5 text-gray-700">
      <li>Unduh template di bawah. Semua kolomnya sudah berformat Teks.</li>
      <li>Isi urutan kolom: NIS Lokal, NISN, Nama, Tanggal Lahir, Kelas. Satu siswa per baris.</li>
      <li>Kelas ditulis seperti VII A, 7A, atau VII-A. Kelas yang belum ada dibuat otomatis.</li>
      <li>NISN, tanggal lahir, dan kelas boleh kosong. Siswa tanpa kelas hanya masuk data induk.</li>
      <li>Tanggal ditulis 2012-05-17 atau 17/05/2012. Pada mode perbarui, isian yang dikosongkan tidak menghapus data lama.</li>
    </ol>
    <p class="text-gray-500">Maksimal 2000 baris dan 2 MB per impor. Kelas yang sudah final tidak bisa diubah anggotanya.</p>
    <a href="<?= url('admin/siswa_template.php') ?>" class="inline-block rounded-md border border-gray-300 px-3 py-2 hover:bg-gray-50">
      <i class="fa-regular fa-file-excel mr-1 text-green-700"></i>Unduh template Excel
    </a>
  </div>
</div>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>
