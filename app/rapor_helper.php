<?php
require_once __DIR__ . '/nilai_helper.php';

const BULAN_ID = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                  7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];

/** '2025-04-17' menjadi '17 April 2025'. Kosong atau tidak valid: tanggal hari ini. */
function tanggal_indonesia(?string $ymd): string {
    $t = $ymd ? strtotime($ymd) : false;
    if (!$t) $t = time();
    return (int)date('j', $t) . ' ' . BULAN_ID[(int)date('n', $t)] . ' ' . date('Y', $t);
}

/** NIP hanya ditampilkan kalau 18 digit (NIP sementara seperti 1001 tidak dicetak). */
function nip_tampil(?string $nip): ?string {
    $nip = trim((string)$nip);
    return preg_match('/^\d{18}$/', $nip) ? $nip : null;
}

/**
 * Menyusun baris tabel rapor dari hasil mapel_kelas().
 * Penomoran dihitung ulang per tingkat: grup dan mapel biasa bernomor 1, 2, 3...; sub-mapel berhuruf A., B., C.
 */
function baris_mapel(array $mapel): array {
    $rows = [];
    $no = 0;
    $lastParent = 0;
    $huruf = 0;
    $idxGrup = null;
    foreach ($mapel as $m) {
        $induk = (int)$m['parent_id'];
        if ($induk) {
            if ($induk !== $lastParent) {
                $lastParent = $induk;
                $huruf = 0;
                $rows[] = ['tipe' => 'grup', 'no' => (string)++$no, 'nama' => $m['induk'], 'span' => 1];
                $idxGrup = array_key_last($rows);
            }
            $rows[] = ['tipe' => 'mapel', 'no' => '', 'label' => chr(65 + min($huruf++, 25)) . '.', 'nama' => $m['nama'], 'id' => (int)$m['id']];
            $rows[$idxGrup]['span']++;
        } else {
            $lastParent = 0;
            $rows[] = ['tipe' => 'mapel', 'no' => (string)++$no, 'label' => '', 'nama' => $m['nama'], 'id' => (int)$m['id']];
        }
    }
    return $rows;
}

/** Memuat semua data rapor satu kelas (atau satu siswa bila $anggotaId diisi). Null jika kelas tidak ada. */
function muat_rapor_kelas(int $kelasId, ?int $anggotaId = null): ?array {
    $pdo = db();

    $st = $pdo->prepare('SELECT k.*, p.tahun_ajaran, p.semester, p.tanggal_cetak, u.nama AS walas_nama, u.nip AS walas_nip
                         FROM kelas k
                         JOIN periode p ON p.id = k.periode_id
                         LEFT JOIN users u ON u.id = k.wali_kelas_id
                         WHERE k.id = ?');
    $st->execute([$kelasId]);
    $k = $st->fetch();
    if (!$k) return null;

    $pengaturan = $pdo->query('SELECT * FROM pengaturan WHERE id = 1')->fetch() ?: [];
    $baris = baris_mapel(mapel_kelas((int)$k['periode_id'], (int)$k['tingkat']));

    $sql = 'SELECT a.id AS aid, s.nama, s.nis_lokal, s.nis_nasional
            FROM anggota_kelas a JOIN siswa s ON s.id = a.siswa_id WHERE a.kelas_id = ?';
    $par = [$kelasId];
    if ($anggotaId) { $sql .= ' AND a.id = ?'; $par[] = $anggotaId; }
    $st = $pdo->prepare($sql . ' ORDER BY s.nama');
    $st->execute($par);
    $siswa = $st->fetchAll();

    $nilai = [];
    $hadir = [];
    $ttd = [];
    if ($siswa) {
        $ids = array_map('intval', array_column($siswa, 'aid'));
        $in = implode(',', array_fill(0, count($ids), '?'));

        $st = $pdo->prepare('SELECT anggota_kelas_id, mapel_id, ' . implode(', ', FIELD_NILAI) . " FROM nilai WHERE anggota_kelas_id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $nilai[(int)$r['anggota_kelas_id']][(int)$r['mapel_id']] = $r;

        $st = $pdo->prepare("SELECT anggota_kelas_id, sakit, izin, alpa FROM ketidakhadiran WHERE anggota_kelas_id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $hadir[(int)$r['anggota_kelas_id']] = $r;

        $st = $pdo->prepare("SELECT anggota_kelas_id, tanda_tangan, nama_penanda, hubungan FROM tanda_tangan_wali WHERE anggota_kelas_id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $ttd[(int)$r['anggota_kelas_id']] = $r;
    }

    foreach ($siswa as $i => $s) {
        $aid = (int)$s['aid'];
        $siswa[$i]['nilai'] = $nilai[$aid] ?? [];
        $siswa[$i]['hadir'] = $hadir[$aid] ?? ['sakit' => 0, 'izin' => 0, 'alpa' => 0];
        $siswa[$i]['ttd'] = $ttd[$aid] ?? null;
    }

    return ['kelas' => $k, 'pengaturan' => $pengaturan, 'baris' => $baris, 'siswa' => $siswa];
}