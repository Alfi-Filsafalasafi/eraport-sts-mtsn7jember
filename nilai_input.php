<?php
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/nilai_helper.php';
require_login();

$periode = periode_aktif();
if (!$periode) { flash('Belum ada periode aktif.'); redirect('nilai.php'); }

$kid = (int)($_GET['kelas'] ?? 0);
$mid = (int)($_GET['mapel'] ?? 0);

$st = db()->prepare('SELECT * FROM kelas WHERE id = ? AND periode_id = ?');
$st->execute([$kid, $periode['id']]);
$kelas = $st->fetch();
if (!$kelas) { flash('Kelas tidak ditemukan pada periode aktif.'); redirect('nilai.php'); }

$daftarMapel = mapel_kelas((int)$periode['id'], (int)$kelas['tingkat']);
$mapel = null;
foreach ($daftarMapel as $m) if ((int)$m['id'] === $mid) $mapel = $m;
if (!$mapel) { flash('Mapel itu tidak berlaku untuk kelas ini.'); redirect('nilai.php?kelas=' . $kid); }

$final = $kelas['status_rapor'] === 'final';
$kembali = 'nilai.php?kelas=' . $kid;
$self = 'nilai_input.php?kelas=' . $kid . '&mapel=' . $mid;

$kolom = implode(', ', array_map(fn($f) => "n.$f", FIELD_NILAI));
$st = db()->prepare("SELECT a.id AS aid, s.nama, s.nis_lokal, s.is_aktif, $kolom
                     FROM anggota_kelas a
                     JOIN siswa s ON s.id = a.siswa_id
                     LEFT JOIN nilai n ON n.anggota_kelas_id = a.id AND n.mapel_id = ?
                     WHERE a.kelas_id = ? ORDER BY s.nama");
$st->execute([$mid, $kid]);
$anggota = $st->fetchAll();

$isi = [];      // teks yang ditampilkan di tiap kotak: $isi[aid][field]
foreach ($anggota as $a) foreach (FIELD_NILAI as $f) $isi[$a['aid']][$f] = tampil_nilai($a[$f]);
$galat = [];
$salah = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($final) { flash('Rapor kelas ini sudah final, nilai terkunci.'); redirect($self); }

    $in = $_POST['n'] ?? [];
    $rencana = [];
    foreach ($anggota as $a) {
        $aid = (int)$a['aid'];
        $vals = [];
        $ada = false;
        foreach (FIELD_NILAI as $f) {
            $raw = trim((string)($in[$aid][$f] ?? ''));
            $isi[$aid][$f] = $raw;
            $v = parse_nilai($raw);
            if ($v === false) {
                $galat[] = $a['nama'] . ', kolom ' . label_field($f) . ': "' . $raw . '" tidak valid (harus angka 0 sampai 100).';
                $salah[$aid . ':' . $f] = true;
                $v = null;
            } elseif ($v !== null) {
                $ada = true;
            }
            $vals[] = $v;
        }
        $rencana[$aid] = [$ada, $vals];
    }

    if (!$galat) {
        $kol = implode(', ', FIELD_NILAI);
        $ph = implode(', ', array_fill(0, count(FIELD_NILAI), '?'));
        $upd = implode(', ', array_map(fn($f) => "$f = VALUES($f)", FIELD_NILAI));
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO nilai (anggota_kelas_id, mapel_id, diinput_oleh, $kol)
                                  VALUES (?, ?, ?, $ph)
                                  ON DUPLICATE KEY UPDATE diinput_oleh = VALUES(diinput_oleh), $upd");
            $hapus = $pdo->prepare('DELETE FROM nilai WHERE anggota_kelas_id = ? AND mapel_id = ?');
            foreach ($rencana as $aid => [$ada, $vals]) {
                if ($ada) $ins->execute(array_merge([$aid, $mid, user()['id']], $vals));
                else      $hapus->execute([$aid, $mid]);
            }
            $pdo->commit();
            flash('Nilai ' . $mapel['tampil'] . ' kelas ' . $kelas['nama_kelas'] . ' disimpan.', 'success');
            redirect($self);
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $galat[] = 'Gagal menyimpan karena kesalahan database. Tidak ada perubahan yang tersimpan.';
        }
    }
}

$cls = 'w-12 rounded border px-1 py-1 text-center text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none';
$title = 'Nilai ' . $mapel['tampil'] . ' - ' . $kelas['nama_kelas'];
require __DIR__ . '/app/partials/header.php';
?>
<a href="<?= url($kembali) ?>" class="text-sm text-gray-500 hover:text-green-700">
  <i class="fa-solid fa-arrow-left mr-1"></i>Kembali ke daftar mapel
</a>
<h1 class="text-xl font-semibold mt-2">Kelas <?= e($kelas['nama_kelas']) ?></h1>

<div class="flex flex-wrap items-center gap-3 my-3">
  <label for="gantiMapel" class="text-sm font-medium">Mapel</label>
  <select id="gantiMapel" data-sekarang="<?= $mid ?>" data-base="<?= e(url('nilai_input.php?kelas=' . $kid . '&mapel=')) ?>"
          class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none">
    <?php foreach ($daftarMapel as $m): ?>
      <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $mid ? 'selected' : '' ?>><?= e($m['tampil']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<?php if ($final): ?>
<div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-lock mr-1"></i>Rapor kelas ini sudah final, jadi nilai hanya bisa dilihat.
</div>
<?php else: ?>
<p class="text-sm text-gray-500 mb-3">
  Isi angka 0 sampai 100. Kolom boleh dikosongkan. Tekan Enter atau panah untuk pindah sel, dan Anda bisa menempel (paste) beberapa sel sekaligus dari Excel.
</p>
<?php endif; ?>

<?php if ($galat): ?>
<div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
  <p class="font-medium mb-1">Nilai belum disimpan. Perbaiki isian yang ditandai merah:</p>
  <ul class="list-disc pl-5 space-y-0.5">
    <?php foreach (array_slice($galat, 0, 10) as $g): ?><li><?= e($g) ?></li><?php endforeach; ?>
    <?php if (count($galat) > 10): ?><li>... dan <?= count($galat) - 10 ?> isian lainnya.</li><?php endif; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!$anggota): ?>
  <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Kelas ini belum berisi siswa.</div>
<?php else: ?>
<form method="post" data-grid data-max="100" autocomplete="off">
  <?= csrf_field() ?>
  <div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
    <table class="text-sm">
      <thead class="bg-green-50 text-green-900">
        <tr>
          <th rowspan="2" class="sticky left-0 z-10 bg-green-50 px-3 py-2 text-left font-medium min-w-[14rem]">Siswa</th>
          <th colspan="7" class="px-2 py-1 font-medium text-center border-l border-green-100">Nilai Tugas</th>
          <th colspan="5" class="px-2 py-1 font-medium text-center border-l border-green-100">Nilai PH</th>
          <th rowspan="2" class="px-2 py-1 font-medium text-center border-l border-green-100">STS</th>
        </tr>
        <tr>
          <?php foreach (FIELD_NILAI as $c => $f): if ($f === 'nilai_sts') continue; ?>
            <th class="px-1 py-1 font-medium text-center <?= $c === 0 || $c === 7 ? 'border-l border-green-100' : '' ?>"><?= label_field($f) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
      <?php foreach ($anggota as $r => $a): $aid = (int)$a['aid']; ?>
        <tr>
          <td class="sticky left-0 z-10 bg-white px-3 py-1.5 whitespace-nowrap <?= $a['is_aktif'] ? '' : 'text-gray-400' ?>" title="NIS <?= e($a['nis_lokal']) ?>">
            <span class="inline-block w-6 text-gray-400"><?= $r + 1 ?></span><?= e($a['nama']) ?>
          </td>
          <?php foreach (FIELD_NILAI as $c => $f):
            $bad = isset($salah[$aid . ':' . $f]);
          ?>
            <td class="px-1 py-1 text-center <?= $c === 0 || $c === 7 || $c === 12 ? 'border-l border-gray-100' : '' ?>">
              <input type="text" inputmode="decimal" name="n[<?= $aid ?>][<?= $f ?>]" value="<?= e($isi[$aid][$f]) ?>"
                     data-r="<?= $r ?>" data-c="<?= $c ?>" <?= $final ? 'readonly' : '' ?>
                     aria-label="<?= e($a['nama']) ?> <?= label_field($f) ?>"
                     class="<?= $cls ?> <?= $bad ? 'border-red-500 bg-red-50' : 'border-gray-300' ?> <?= $final ? 'bg-gray-50' : '' ?>">
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!$final): ?>
  <div class="sticky bottom-0 mt-4 -mx-4 sm:-mx-6 px-4 sm:px-6 py-3 bg-gray-50 border-t border-gray-200 flex items-center gap-3">
    <button class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">
      <i class="fa-regular fa-floppy-disk mr-1"></i>Simpan nilai
    </button>
    <span class="text-sm text-gray-500"><?= count($anggota) ?> siswa &middot; <?= e($mapel['tampil']) ?></span>
  </div>
  <?php endif; ?>
</form>
<script src="<?= url('assets/js/grid.js') ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/app/partials/footer.php'; ?>