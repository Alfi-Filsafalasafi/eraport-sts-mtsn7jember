<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/kelas_helper.php';
require __DIR__ . '/../app/mapel_helper.php';
require_role('admin');

$periodeList = daftar_periode();
$pid = (int)($_GET['periode'] ?? 0);
if (!in_array($pid, array_map('intval', array_column($periodeList, 'id')), true)) {
    $aktif = periode_aktif();
    $pid = $aktif ? (int)$aktif['id'] : (int)($periodeList[0]['id'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo = db();
    $aksi = $_POST['aksi'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    try {
        if ($aksi === 'simpan_tingkat' && $pid) {
            $leaf = array_map('intval', $pdo->query('SELECT id FROM mapel WHERE is_header = 0')->fetchAll(PDO::FETCH_COLUMN));
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM mapel_tingkat WHERE periode_id = ?')->execute([$pid]);
            $ins = $pdo->prepare('INSERT INTO mapel_tingkat (periode_id, tingkat, mapel_id) VALUES (?, ?, ?)');
            foreach ((array)($_POST['mt'] ?? []) as $mid => $tingkats) {
                $mid = (int)$mid;
                if (!in_array($mid, $leaf, true)) continue;
                foreach (array_unique(array_map('intval', (array)$tingkats)) as $t) {
                    if (isset(TINGKAT_MAPEL[$t])) $ins->execute([$pid, $t, $mid]);
                }
            }
            sinkron_header($pid);
            $pdo->commit();
            flash('Pengaturan mapel per tingkat disimpan.', 'success');

        } elseif ($aksi === 'geser') {
            geser_mapel($id, ($_POST['arah'] ?? '') === 'naik' ? -1 : 1);

        } elseif ($aksi === 'toggle') {
            $st = $pdo->prepare('SELECT is_aktif FROM mapel WHERE id = ?');
            $st->execute([$id]);
            $cur = $st->fetchColumn();
            if ($cur !== false) {
                $baru = $cur ? 0 : 1;
                $pdo->prepare('UPDATE mapel SET is_aktif = ? WHERE id = ? OR parent_id = ?')->execute([$baru, $id, $id]);
                flash($baru ? 'Mapel diaktifkan.' : 'Mapel dinonaktifkan dan tidak tampil di rapor.', 'success');
            }

        } elseif ($aksi === 'hapus') {
            $st = $pdo->prepare('SELECT nama,
                                        (SELECT COUNT(*) FROM mapel c WHERE c.parent_id = mapel.id) AS anak,
                                        (SELECT COUNT(*) FROM nilai n WHERE n.mapel_id = mapel.id) AS jml_nilai
                                 FROM mapel WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch();
            if (!$r) {
                flash('Mapel tidak ditemukan.');
            } elseif ((int)$r['anak'] > 0) {
                flash("{$r['nama']} masih punya sub-mapel. Hapus atau pindahkan sub-mapelnya dulu.");
            } elseif ((int)$r['jml_nilai'] > 0) {
                flash("{$r['nama']} sudah punya data nilai, jadi tidak bisa dihapus. Nonaktifkan saja.");
            } else {
                $pdo->prepare('DELETE FROM mapel WHERE id = ?')->execute([$id]);
                rapikan_urutan();
                flash("{$r['nama']} dihapus.", 'success');
            }
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('Terjadi kesalahan database. Perubahan dibatalkan.');
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// ---- Data tampilan ----
$top = [];
$anak = [];
foreach (db()->query('SELECT * FROM mapel ORDER BY urutan, id')->fetchAll() as $m) {
    if ($m['parent_id'] === null) $top[] = $m;
    else $anak[(int)$m['parent_id']][] = $m;
}
$baris = [];
foreach ($top as $t) {
    $baris[] = $t;
    foreach ($anak[(int)$t['id']] ?? [] as $c) $baris[] = $c;
}

$cek = [];
if ($pid) {
    $st = db()->prepare('SELECT mapel_id, tingkat FROM mapel_tingkat WHERE periode_id = ?');
    $st->execute([$pid]);
    foreach ($st->fetchAll() as $r) $cek[(int)$r['mapel_id']][(int)$r['tingkat']] = true;
}

$periodeDipilih = null;
foreach ($periodeList as $p) if ((int)$p['id'] === $pid) $periodeDipilih = $p;

$tombol = function (string $aksi, int $id, string $ikon, string $judul, array $extra = [], string $konfirm = '', bool $mati = false) {
    if ($mati) return '<span class="px-1 text-gray-300"><i class="fa-solid ' . $ikon . '"></i></span>';
    $h = '<form method="post" class="inline">' . csrf_field()
       . '<input type="hidden" name="aksi" value="' . e($aksi) . '"><input type="hidden" name="id" value="' . $id . '">';
    foreach ($extra as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    $js = $konfirm !== '' ? ' onclick="return confirm(' . e(json_encode($konfirm)) . ')"' : '';
    return $h . '<button type="submit" class="px-1 text-gray-500 hover:text-green-700" title="' . e($judul) . '"' . $js . '><i class="fa-solid ' . $ikon . '"></i></button></form>';
};

$title = 'Kelola Mapel';
require __DIR__ . '/../app/partials/header.php';
?>
<div class="flex flex-wrap items-end justify-between gap-3 mb-4">
  <div>
    <h1 class="text-xl font-semibold">Kelola Mapel</h1>
    <p class="text-sm text-gray-500">Atur daftar mapel, urutan tampil di rapor, dan mapel apa saja yang berlaku di tiap tingkat.</p>
  </div>
  <div class="flex gap-2">
    <button type="submit" form="matriks" class="rounded-md border border-gray-300 bg-white hover:bg-gray-50 text-sm font-medium py-2 px-4">
      <i class="fa-regular fa-floppy-disk mr-1 text-green-700"></i>Simpan centang tingkat
    </button>
    <a href="<?= url('admin/mapel_form.php' . ($pid ? '?periode=' . $pid : '')) ?>" class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">
      <i class="fa-solid fa-plus mr-1"></i>Tambah mapel
    </a>
  </div>
</div>

<?php if (!$periodeList): ?>
  <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    Belum ada periode. Buat periode dulu di menu Kelola Periode.
  </div>
<?php else: ?>

<form method="get" class="mb-3">
  <label for="periode" class="block text-sm font-medium mb-1">Pengaturan tingkat untuk periode</label>
  <select id="periode" name="periode" onchange="this.form.submit()"
          class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-green-600 focus:ring-1 focus:ring-green-600 focus:outline-none">
    <?php foreach ($periodeList as $p): ?>
      <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $pid ? 'selected' : '' ?>><?= e(label_periode($p)) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<div class="mb-4 rounded-md border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600 space-y-1">
  <p><i class="fa-solid fa-circle-info mr-1 text-green-700"></i>Centang tingkat hanya berlaku untuk periode yang dipilih, jadi rapor semester lama tidak berubah. Periode baru otomatis menyalin pengaturan periode sebelumnya.</p>
  <p>Nomor dan huruf (A, B, C) dibuat otomatis mengikuti urutan. Mapel yang <strong>dinonaktifkan</strong> hilang dari rapor di semua periode. Kalau hanya ingin meniadakan di satu tingkat atau satu periode, cukup cabut centangnya.</p>
</div>

<form id="matriks" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="aksi" value="simpan_tingkat">
</form>

<div class="bg-white border border-gray-200 rounded-lg overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-green-50 text-green-900 text-left">
      <tr>
        <th class="px-4 py-2 font-medium w-14">No</th>
        <th class="px-4 py-2 font-medium">Mata pelajaran</th>
        <?php foreach (TINGKAT_MAPEL as $t => $r): ?>
          <th class="px-2 py-2 font-medium text-center w-14"><?= $r ?></th>
        <?php endforeach; ?>
        <th class="px-4 py-2 font-medium text-center">Urutan</th>
        <th class="px-4 py-2 font-medium text-right">Aksi</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
    <?php
    $no = 0;
    $huruf = [];
    foreach ($baris as $m):
        $mid = (int)$m['id'];
        $header = (bool)$m['is_header'];
        $sub = $m['parent_id'] !== null;
        $induk = $sub ? (int)$m['parent_id'] : 0;
        if ($sub) { $huruf[$induk] = ($huruf[$induk] ?? -1) + 1; $nomor = chr(65 + min($huruf[$induk], 25)) . '.'; }
        else { $nomor = (string)(++$no); }
        $sib = $sub ? $anak[$induk] : $top;
        $awal = (int)$sib[0]['id'] === $mid;
        $akhir = (int)end($sib)['id'] === $mid;
        $aktifRow = (bool)$m['is_aktif'];
    ?>
      <tr class="<?= $header ? 'bg-gray-50 font-medium' : '' ?> <?= $aktifRow ? '' : 'text-gray-400' ?>">
        <td class="px-4 py-2 <?= $sub ? 'text-right text-gray-500' : '' ?>"><?= e($nomor) ?></td>
        <td class="px-4 py-2 <?= $sub ? 'pl-8' : '' ?>">
          <?= e($m['nama']) ?>
          <?php if (!$aktifRow): ?><span class="text-xs">(nonaktif)</span><?php endif; ?>
        </td>
        <?php foreach (TINGKAT_MAPEL as $t => $r): ?>
          <td class="px-2 py-2 text-center">
            <?php if (!$header): ?>
              <input type="checkbox" form="matriks" name="mt[<?= $mid ?>][]" value="<?= $t ?>"
                     <?= !empty($cek[$mid][$t]) ? 'checked' : '' ?> aria-label="<?= e($m['nama']) ?> untuk tingkat <?= $r ?>"
                     class="rounded border-gray-300 text-green-700 focus:ring-green-600">
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
        <td class="px-4 py-2 text-center whitespace-nowrap">
          <?= $tombol('geser', $mid, 'fa-arrow-up', 'Naikkan', ['arah' => 'naik'], '', $awal) ?>
          <?= $tombol('geser', $mid, 'fa-arrow-down', 'Turunkan', ['arah' => 'turun'], '', $akhir) ?>
        </td>
        <td class="px-4 py-2 text-right whitespace-nowrap">
          <a href="<?= url('admin/mapel_form.php?id=' . $mid . '&periode=' . $pid) ?>" class="px-1 text-gray-500 hover:text-green-700" title="Edit">
            <i class="fa-solid fa-pen"></i>
          </a>
          <?= $tombol('toggle', $mid, $aktifRow ? 'fa-eye-slash' : 'fa-eye', $aktifRow ? 'Nonaktifkan' : 'Aktifkan', [],
                      $aktifRow ? 'Nonaktifkan ' . $m['nama'] . '? Mapel ini tidak akan tampil di rapor.' : '') ?>
          <?= $tombol('hapus', $mid, 'fa-trash-can', 'Hapus', [], 'Hapus ' . $m['nama'] . '?') ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="mt-4">
  <button type="submit" form="matriks" class="rounded-md bg-green-700 hover:bg-green-800 text-white text-sm font-medium py-2 px-4">
    <i class="fa-regular fa-floppy-disk mr-1"></i>Simpan centang tingkat
  </button>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/partials/footer.php'; ?>