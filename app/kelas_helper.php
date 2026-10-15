<?php
const ROMAWI = [7 => 'VII', 8 => 'VIII', 9 => 'IX'];

/** Susun nama kelas, misalnya (7, 'a') menjadi 'VII A'. */
function nama_kelas(int $tingkat, string $rombel): string {
    return trim((ROMAWI[$tingkat] ?? (string)$tingkat) . ' ' . strtoupper(trim($rombel)));
}

/** Ambil bagian rombel dari 'VII A' (hasil 'A'). Null jika nama tidak berpola VII/VIII/IX. */
function pecah_nama_kelas(string $nama): ?string {
    return preg_match('/^(?:VIII|VII|IX)\s*(.*)$/i', trim($nama), $m) ? trim($m[1]) : null;
}

function daftar_periode(): array {
    return db()->query('SELECT id, tahun_ajaran, semester, is_aktif FROM periode
                        ORDER BY tahun_ajaran DESC, semester DESC')->fetchAll();
}

function label_periode(array $p): string {
    return 'Semester ' . $p['semester'] . ' ' . $p['tahun_ajaran'] . ($p['is_aktif'] ? ' (aktif)' : '');
}