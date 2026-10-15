<?php
const FIELD_NILAI = ['tp1', 'tp2', 'tp3', 'tp4', 'tp5', 'tp6', 'tp7', 'ph1', 'ph2', 'ph3', 'ph4', 'ph5', 'nilai_sts'];

function label_field(string $f): string {
    return $f === 'nilai_sts' ? 'STS' : strtoupper($f);
}

/** Mengembalikan float (0-100, maksimal 2 desimal), null jika kosong, atau false jika tidak valid. */
function parse_nilai(string $v): float|null|false {
    $v = trim(str_replace(',', '.', $v));
    if ($v === '') return null;
    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $v)) return false;
    $f = (float)$v;
    return $f <= 100 ? $f : false;
}

/** '85.00' menjadi '85', '85.50' menjadi '85.5', null menjadi ''. */
function tampil_nilai(mixed $v): string {
    if ($v === null || $v === '') return '';
    return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
}

/** [periode aktif, daftar kelas periode aktif]. */
function kelas_periode_aktif(): array {
    $p = periode_aktif();
    if (!$p) return [null, []];
    $st = db()->prepare('SELECT k.id, k.nama_kelas, k.tingkat, k.status_rapor, k.wali_kelas_id,
                                (SELECT COUNT(*) FROM anggota_kelas a WHERE a.kelas_id = k.id) AS jml
                         FROM kelas k WHERE k.periode_id = ? ORDER BY k.tingkat, k.nama_kelas');
    $st->execute([$p['id']]);
    return [$p, $st->fetchAll()];
}

/** Kelas yang diminta; kalau tidak ada: kelas yang diwalikan user ini; kalau tidak ada: kelas pertama. */
function pilih_kelas(array $daftar, int $minta): ?array {
    foreach ($daftar as $k) if ((int)$k['id'] === $minta) return $k;
    $uid = (int)user()['id'];
    foreach ($daftar as $k) if ((int)$k['wali_kelas_id'] === $uid) return $k;
    return $daftar[0] ?? null;
}

/** Mapel (bukan judul grup) yang berlaku untuk satu tingkat pada satu periode, urut seperti di rapor. */
function mapel_kelas(int $periodeId, int $tingkat): array {
    $st = db()->prepare('SELECT m.id, m.nama, m.parent_id, p.nama AS induk
                         FROM mapel_tingkat mt
                         JOIN mapel m ON m.id = mt.mapel_id AND m.is_header = 0 AND m.is_aktif = 1
                         LEFT JOIN mapel p ON p.id = m.parent_id
                         WHERE mt.periode_id = ? AND mt.tingkat = ? AND (m.parent_id IS NULL OR p.is_aktif = 1)
                         ORDER BY m.urutan');
    $st->execute([$periodeId, $tingkat]);
    return array_map(fn($m) => $m + ['tampil' => $m['induk'] ? $m['induk'] . ' - ' . $m['nama'] : $m['nama']], $st->fetchAll());
}

/** Deretan tombol pilihan kelas. */
function pills_kelas(array $daftar, ?array $terpilih, string $dasar): string {
    $h = '<div class="flex flex-wrap gap-2 mb-5">';
    foreach ($daftar as $k) {
        $aktif = $terpilih && (int)$k['id'] === (int)$terpilih['id'];
        $kls = $aktif ? 'bg-green-700 border-green-700 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50';
        $h .= '<a href="' . e(url($dasar . '?kelas=' . (int)$k['id'])) . '" class="rounded-full border px-3 py-1 text-sm ' . $kls . '">'
            . e($k['nama_kelas']) . ' <span class="text-xs opacity-75">(' . (int)$k['jml'] . ')</span>'
            . ($k['status_rapor'] === 'final' ? ' <i class="fa-solid fa-lock text-xs"></i>' : '') . '</a>';
    }
    return $h . '</div>';
}