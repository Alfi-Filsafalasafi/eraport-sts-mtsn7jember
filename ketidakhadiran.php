<?php
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/nilai_helper.php';
require_login();

[$periode, $daftarKelas] = kelas_periode_aktif();
$kelas = $periode ? pilih_kelas($daftarKelas, (int)($_GET['kelas'] ?? 0)) : null;
$final = $kelas && $kelas['status_rapor'] === 'final';
$self = $kelas ? 'ketidakhadiran.php?kelas=' . (int)$kelas['id'] : 'ketidakhadiran.php';

const FIELD_HADIR = ['sakit', 'izin', 'alpa'];

/** Jumlah hari: kosong dihitung 0, maksimal 365. false jika tidak valid. */
function parse_hari(string $v): int|false {
    $v = trim($v);
    if ($v === '') return 0;
    return (preg_match('/^\d{1,3}$/', $v) && (int)$v <= 365) ? (int)$v : false;
}

$anggota = [];
$isi = [];
$galat = [];
$salah = [];

if ($kelas) {
    $st = db()->prepare('SELECT a.id AS aid, s.nama, s.nis_lokal, s.is_aktif,
                                COALESCE(k.sakit, 0) AS sakit, COALESCE(k.izin, 0) AS izin, COALESCE(k.alpa, 0) AS alpa
                         FROM anggota_kelas a
                         JOIN siswa s ON s.id = a.siswa_id
                         LEFT JOIN ketidakhadiran k ON k.anggota_kelas_id = a.id
                         WHERE a.kelas_id = ? ORDER BY s.nama');
    $st->execute([$kelas['id']]);
    $anggota = $st->fetchAll();
    foreach ($anggota as $a) foreach (FIELD_HADIR as $f) $isi[$a['aid']][$f] = (string)$a[$f];
}

if ($kelas && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($final) { flash('Rapor kelas ini sudah final, ketidakhadiran terkunci.'); redirect($self); }

    $in = $_POST['h'] ?? [];
    $rencana = [];
    foreach ($anggota as $a) {
        $aid = (int)$a['aid'];
        $vals = [];
        foreach (FIELD_HADIR as $f) {
            $raw = trim((string)($in[$aid][$f] ?? ''));
            $isi[$aid][$f] = $raw;
            $v = parse_hari($raw);
            if ($v === false) {
                $galat[] = $a['nama'] . ', kolom ' . ucfirst($f) . ': "' . $raw . '" tidak valid (angka 0 sampai 365).';
                $salah[$aid . ':' . $f] = true;
                $v = 0;
            }
            $vals[] = $v;
        }
        $rencana[$aid] = $vals;
    }

    if (!$galat) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare('INSERT INTO ketidakhadiran (anggota_kelas_id, sakit, izin, alpa) VALUES (?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE sakit = VALUES(sakit), izin = VALUES(izin), alpa = VALUES(alpa)');
            foreach ($rencana as $aid => $v) $ins->execute([$aid, $v[0], $v[1], $v[2]]);
            $pdo->commit();
            flash('Ketidakhadiran kelas ' . $kelas['nama_kelas'] . ' disimpan.', 'success');
            redirect($self);
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $galat[] = 'Gagal menyimpan karena kesalahan database. Tidak ada perubahan yang tersimpan.';
        }
    }
}

$cls = 'w-16 rounded border px-1 py-1 text-center text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none';
$title = 'Ketidakhadiran';
require __DIR__ . '/app/partials/header.php';
?>
<h1 class="text-xl font-semibold mb-1">Ketidakhadiran</h1>
<p class="text-sm text-gray-500 mb-4">
  <?php if ($periode): ?>Semester <?= e($periode['semester']) ?> <?= e($periode['tahun_ajaran']) ?>. Isi jumlah hari sakit, izin, dan alpa tiap siswa.
  <?php else: ?>Belum ada periode aktif.<?php endif; ?>
</p>

<?php if (!$periode): ?>
  <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    Belum ada periode aktif. Admin perlu mengaktifkan satu periode di menu Kelola Periode.
  </div>
<?php elseif (!$daftarKelas): ?>
  <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    Belum ada kelas pada periode aktif. Admin perlu membuat kelas di menu Kelola Kelas.
  </div>
<?php else: ?>

<?= pills_kelas($daftarKelas, $kelas, 'ketidakhadiran.php') ?>

<?php if ($final): ?>
<div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
  <i class="fa-solid fa-lock mr-1"></i>Rapor kelas ini sudah final, jadi ketidakhadiran hanya bisa dilihat.
</div>
<?php endif; ?>

<?php if ($galat): ?>
<div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
  <p class="font-medium mb-1">Data belum disimpan. Perbaiki isian yang ditandai merah:</p>
  <ul class="list-disc pl-5 space-y-0.5">
    <?php foreach (array_slice($galat, 0, 10) as $g): ?><li><?= e($g) ?></li><?php endforeach; ?>
    <?php if (count($galat) > 10): ?><li>... dan <?= count($galat) - 10 ?> isian lainnya.</li><?php endif; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!$anggota): ?>
  <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Kelas ini belum berisi siswa.</div>
<?php else: ?>
<form method="post" action="<?= e(url($self)) ?>" data-grid data-max="365" data-desimal="0" autocomplete="off">
  <?= csrf_field() ?>
  <div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-green-50 text-green-900">
        <tr>
          <th class="px-4 py-2 text-left font-medium">Siswa</th>
          <th class="px-2 py-2 font-medium text-center">Sakit</th>
          <th class="px-2 py-2 font-medium text-center">Izin</th>
          <th class="px-2 py-2 font-medium text-center">Alpa</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
      <?php foreach ($anggota as $r => $a): $aid = (int)$a['aid']; ?>
        <tr>
          <td class="px-4 py-1.5 whitespace-nowrap <?= $a['is_aktif'] ? '' : 'text-gray-400' ?>" title="NIS <?= e($a['nis_lokal']) ?>">
            <span class="inline-block w-6 text-gray-400"><?= $r + 1 ?></span><?= e($a['nama']) ?>
          </td>
          <?php foreach (FIELD_HADIR as $c => $f): $bad = isset($salah[$aid . ':' . $f]); ?>
            <td class="px-2 py-1 text-center">
              <input type="text" inputmode="numeric" name="h[<?= $aid ?>][<?= $f ?>]" value="<?= e($isi[$aid][$f]) ?>"
                     data-r="<?= $r ?>" data-c="<?= $c ?>" <?= $final ? 'readonly' : '' ?>
                     aria-label="<?= e($a['nama']) ?> <?= ucfirst($f) ?>"
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
      <i class="fa-regular fa-floppy-disk mr-1"></i>Simpan ketidakhadiran
    </button>
    <span class="text-sm text-gray-500"><?= count($anggota) ?> siswa &middot; kelas <?= e($kelas['nama_kelas']) ?></span>
  </div>
  <?php endif; ?>
</form>
<script src="<?= url('assets/js/grid.js') ?>"></script>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/app/partials/footer.php'; ?>