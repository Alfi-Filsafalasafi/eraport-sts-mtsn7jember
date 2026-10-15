<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/kelas_helper.php';
require_role('admin');

$periodeList = daftar_periode();
$petaPeriode = [];
foreach ($periodeList as $p) $petaPeriode[(int)$p['id']] = $p;

$hasil = null;
$tujuanId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();
    try {
        $src  = (int)($_POST['sumber'] ?? 0);
        $dst  = (int)($_POST['tujuan'] ?? 0);
        $naik = ($_POST['mode'] ?? '') === 'naik';
        $lulus = $naik && isset($_POST['lulus']);
        $tujuanId = $dst;

        if (!isset($petaPeriode[$src]) || !isset($petaPeriode[$dst])) throw new RuntimeException('Periode sumber atau tujuan tidak valid.');
        if ($src === $dst) throw new RuntimeException('Periode sumber dan tujuan tidak boleh sama.');

        $hasil = ['kelas_baru' => 0, 'kelas_ada' => 0, 'siswa' => 0, 'dilewati' => 0, 'lulus' => 0, 'nonaktif' => 0, 'galat' => []];

        $pdo->beginTransaction();

        $st = $pdo->prepare('SELECT * FROM kelas WHERE periode_id = ? ORDER BY tingkat, nama_kelas');
        $st->execute([$src]);
        $kelasSumber = $st->fetchAll();

        $st->execute([$dst]);
        $kelasTujuan = [];
        $waliPakai = [];
        foreach ($st->fetchAll() as $k) {
            $kelasTujuan[strtoupper($k['nama_kelas'])] = $k;
            if ($k['wali_kelas_id']) $waliPakai[(int)$k['wali_kelas_id']] = true;
        }

        $jmlAnggota = $pdo->prepare('SELECT COUNT(*) FROM anggota_kelas WHERE kelas_id = ?');
        $insKelas = $pdo->prepare("INSERT INTO kelas (periode_id, nama_kelas, tingkat, fase, wali_kelas_id) VALUES (?, ?, ?, ?, ?)");
        $salin = $pdo->prepare('INSERT INTO anggota_kelas (periode_id, kelas_id, siswa_id)
                                SELECT ?, ?, a.siswa_id
                                FROM anggota_kelas a JOIN siswa s ON s.id = a.siswa_id
                                WHERE a.kelas_id = ? AND s.is_aktif = 1
                                  AND NOT EXISTS (SELECT 1 FROM anggota_kelas x WHERE x.periode_id = ? AND x.siswa_id = a.siswa_id)');
        $nonaktifkan = $pdo->prepare('UPDATE siswa SET is_aktif = 0
                                      WHERE id IN (SELECT siswa_id FROM anggota_kelas WHERE kelas_id = ?)');

        foreach ($kelasSumber as $k) {
            $jmlAnggota->execute([$k['id']]);
            $n = (int)$jmlAnggota->fetchColumn();
            $wali = null;

            if ($naik) {
                if ((int)$k['tingkat'] === 9) {                     // kelas IX: tidak disalin (lulus)
                    $hasil['lulus'] += $n;
                    if ($lulus && $n) { $nonaktifkan->execute([$k['id']]); $hasil['nonaktif'] += $nonaktifkan->rowCount(); }
                    continue;
                }
                $rombel = pecah_nama_kelas($k['nama_kelas']);
                if ($rombel === null) {
                    $hasil['galat'][] = "Kelas {$k['nama_kelas']} dilewati: namanya tidak bisa dinaikkan otomatis.";
                    continue;
                }
                $tingkatBaru = (int)$k['tingkat'] + 1;
                $namaBaru = nama_kelas($tingkatBaru, $rombel);
            } else {
                $tingkatBaru = (int)$k['tingkat'];
                $namaBaru = $k['nama_kelas'];
                $w = $k['wali_kelas_id'] ? (int)$k['wali_kelas_id'] : 0;
                if ($w && !isset($waliPakai[$w])) $wali = $w;
                elseif ($w) $hasil['galat'][] = "Wali kelas $namaBaru tidak disalin karena guru tersebut sudah menjadi wali kelas lain di periode tujuan.";
            }

            $kunci = strtoupper($namaBaru);
            if (isset($kelasTujuan[$kunci])) {
                if ($kelasTujuan[$kunci]['status_rapor'] === 'final') {
                    $hasil['galat'][] = "Kelas $namaBaru di periode tujuan sudah final, jadi dilewati.";
                    continue;
                }
                $idBaru = (int)$kelasTujuan[$kunci]['id'];
                $hasil['kelas_ada']++;
            } else {
                $insKelas->execute([$dst, $namaBaru, $tingkatBaru, $k['fase'], $wali]);
                $idBaru = (int)$pdo->lastInsertId();
                $kelasTujuan[$kunci] = ['id' => $idBaru, 'status_rapor' => 'draft'];
                if ($wali) $waliPakai[$wali] = true;
                $hasil['kelas_baru']++;
            }

            $salin->execute([$dst, $idBaru, $k['id'], $dst]);
            $disalin = $salin->rowCount();
            $hasil['siswa'] += $disalin;
            $hasil['dilewati'] += max(0, $n - $disalin);
        }
        $pdo->commit();
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($ex->getMessage());
        redirect('admin/kelas_salin.php' . ($tujuanId ? '?tujuan=' . $tujuanId : ''));
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('Penyalinan gagal karena kesalahan database. Tidak ada data yang berubah.');
        redirect('admin/kelas_salin.php');
    }
}

// Nilai awal form
$dstDefault = $tujuanId ?: (int)($_GET['tujuan'] ?? 0);
if (!isset($petaPeriode[$dstDefault])) { $a = periode_aktif(); $dstDefault = $a ? (int)$a['id'] : (int)($periodeList[0]['id'] ?? 0); }
$srcDefault = 0;
$ids = array_map('intval', array_column($periodeList, 'id'));
$pos = array_search($dstDefault, $ids, true);
if ($pos !== false && isset($ids[$pos + 1])) $srcDefault = $ids[$pos + 1];
elseif ($ids) foreach ($ids as $i) if ($i !== $dstDefault) { $srcDefault = $i; break; }
$modeDefault = ($srcDefault && isset($petaPeriode[$dstDefault])
    && $petaPeriode[$srcDefault]['tahun_ajaran'] === $petaPeriode[$dstDefault]['tahun_ajaran']) ? 'sama' : 'naik';

$sel = 'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none';
$title = 'Salin Kelas';
require __DIR__ . '/../app/partials/header.php';
?>
<a href="<?= url('admin/kelas.php' . ($dstDefault ? '?periode=' . $dstDefault : '')) ?>" class="text-sm text-gray-500 hover:text-green-700">
  <i class="fa-solid fa-arrow-left mr-1"></i>Kembali ke daftar kelas
</a>
<h1 class="text-xl font-semibold mt-2 mb-4">Salin Kelas dari Periode Lain</h1>

<?php if ($hasil): ?>
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-6">
  <h2 class="font-semibold mb-2">Hasil penyalinan</h2>
  <ul class="text-sm space-y-1">
    <li><i class="fa-solid fa-circle-check text-green-700 mr-2"></i><?= $hasil['kelas_baru'] ?> kelas baru dibuat<?= $hasil['kelas_ada'] ? ', ' . $hasil['kelas_ada'] . ' kelas sudah ada dan dipakai' : '' ?></li>
    <li><i class="fa-solid fa-people-group text-green-700 mr-2"></i><?= $hasil['siswa'] ?> siswa dimasukkan ke kelas</li>
    <?php if ($hasil['dilewati']): ?><li><i class="fa-solid fa-forward text-gray-500 mr-2"></i><?= $hasil['dilewati'] ?> siswa dilewati (sudah punya kelas di periode tujuan atau sudah nonaktif)</li><?php endif; ?>
    <?php if ($hasil['lulus']): ?><li><i class="fa-solid fa-graduation-cap text-green-700 mr-2"></i><?= $hasil['lulus'] ?> siswa kelas IX tidak disalin (lulus)<?= $hasil['nonaktif'] ? ', ' . $hasil['nonaktif'] . ' di antaranya dinonaktifkan' : '' ?></li><?php endif; ?>
  </ul>
  <?php if ($hasil['galat']): ?>
    <ul class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 space-y-1">
      <?php foreach ($hasil['galat'] as $g): ?><li><?= e($g) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <a href="<?= url('admin/kelas.php?periode=' . $tujuanId) ?>" class="inline-block mt-4 text-sm text-green-700 hover:underline">
    Lihat daftar kelas periode tujuan <i class="fa-solid fa-arrow-right ml-1"></i>
  </a>
</div>
<?php endif; ?>

<?php if (count($periodeList) < 2): ?>
  <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    Perlu minimal dua periode untuk menyalin kelas. Tambahkan periode baru di menu Kelola Periode.
  </div>
<?php else: ?>
<form method="post" class="bg-white border border-gray-200 rounded-lg p-6 max-w-xl space-y-5"
      onsubmit="return confirm('Salin kelas dari periode sumber ke periode tujuan?')">
  <?= csrf_field() ?>
  <div class="grid sm:grid-cols-2 gap-4">
    <div>
      <label for="sumber" class="block text-sm font-medium mb-1">Salin dari (sumber)</label>
      <select id="sumber" name="sumber" class="<?= $sel ?>">
        <?php foreach ($periodeList as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $srcDefault ? 'selected' : '' ?>><?= e(label_periode($p)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="tujuan" class="block text-sm font-medium mb-1">Salin ke (tujuan)</label>
      <select id="tujuan" name="tujuan" class="<?= $sel ?>">
        <?php foreach ($periodeList as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $dstDefault ? 'selected' : '' ?>><?= e(label_periode($p)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <fieldset class="space-y-3 text-sm">
    <legend class="font-medium mb-1">Jenis penyalinan</legend>
    <label class="flex gap-2">
      <input type="radio" name="mode" value="sama" <?= $modeDefault === 'sama' ? 'checked' : '' ?> class="mt-0.5 text-green-700 focus:ring-green-600">
      <span><strong>Semester berikutnya (tahun ajaran sama).</strong><br>
        <span class="text-gray-500">Kelas, wali kelas, dan anggotanya disalin persis sama.</span></span>
    </label>
    <label class="flex gap-2">
      <input type="radio" name="mode" value="naik" <?= $modeDefault === 'naik' ? 'checked' : '' ?> class="mt-0.5 text-green-700 focus:ring-green-600">
      <span><strong>Tahun ajaran baru (naik kelas).</strong><br>
        <span class="text-gray-500">VII menjadi VIII dan VIII menjadi IX, rombel tetap. Wali kelas dikosongkan untuk diatur ulang. Kelas IX tidak disalin.</span></span>
    </label>
    <label class="flex items-center gap-2 pl-6 text-gray-700">
      <input type="checkbox" name="lulus" value="1" class="rounded border-gray-300 text-green-700 focus:ring-green-600">
      Nonaktifkan siswa kelas IX (lulus) pada mode naik kelas
    </label>
  </fieldset>

  <p class="text-xs text-gray-500">
    Siswa yang sudah punya kelas di periode tujuan tidak ditimpa, dan siswa nonaktif tidak ikut disalin.
    Nilai dan ketidakhadiran tidak ikut disalin. Siswa baru kelas VII dimasukkan lewat impor siswa.
  </p>

  <button class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">
    <i class="fa-regular fa-copy mr-1"></i>Salin sekarang
  </button>
</form>
<?php endif; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>